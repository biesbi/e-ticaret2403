/**
 * PayTR Ödeme Helper Script
 * BoomerItems için PayTR yeni sekme entegrasyonu
 */

(function() {
  'use strict';

  console.log('🚀 PayTR Helper Script yüklendi');
  let activeOrderId = '';
  let paymentPollInterval = null;
  let paymentChannel = null;
  let paymentHandled = false; // overlay olmasa bile çift çalışmayı engeller
  let inlinePaytrWasVisible = false;
  let resetInProgress = false;
  let storageListenerBound = false;
  const CHECKOUT_SNAPSHOT_KEY = 'paytr_checkout_snapshot';
  const INLINE_PAYTR_SELECTOR = '.paytr-iframe-container, .paytr-iframe, iframe#paytriframe';
  const INLINE_PAYTR_CLOSE_SELECTOR = '.detail-close-btn, .checkout-close-btn, .modal-close-btn, .mfp-close, button[aria-label="Kapat"], button[aria-label="Close"], button[title="Kapat"]';

  function getRememberedOrderId() {
    if (activeOrderId) return activeOrderId;

    try {
      return localStorage.getItem('lastOrderId') || '';
    } catch (e) {
      return '';
    }
  }

  function clearCheckoutSessionStorage(orderId, keepSnapshot) {
    try {
      localStorage.removeItem('lastOrderId');
      localStorage.removeItem('lastOrderTotal');
      localStorage.removeItem('paytr_result');

      if (keepSnapshot) {
        return;
      }

      const raw = localStorage.getItem(CHECKOUT_SNAPSHOT_KEY);
      if (!raw) return;

      const snapshot = JSON.parse(raw);
      if (!orderId || !snapshot.orderId || snapshot.orderId === orderId) {
        localStorage.removeItem(CHECKOUT_SNAPSHOT_KEY);
      }
    } catch (e) {}
  }

  function hasInlinePaytrOpen() {
    return !!document.querySelector(INLINE_PAYTR_SELECTOR);
  }

  function syncInlinePaytrState() {
    if (paymentHandled) {
      inlinePaytrWasVisible = false;
      return;
    }

    if (document.querySelector('.order-success-page, .order-success')) {
      inlinePaytrWasVisible = false;
      return;
    }

    if (hasInlinePaytrOpen()) {
      inlinePaytrWasVisible = true;
      return;
    }

    if (!inlinePaytrWasVisible || resetInProgress) {
      return;
    }

    const orderId = getRememberedOrderId();
    inlinePaytrWasVisible = false;

    if (!orderId) {
      clearCheckoutSessionStorage('', false);
      return;
    }

    resetCancelledCheckout(orderId);
  }

  function saveCheckoutSnapshot(orderId, options) {
    try {
      const snapshot = {
        orderId: orderId || '',
        savedAt: Date.now(),
        cartItems: JSON.parse(localStorage.getItem('cartItems') || '[]')
      };

      if (options && typeof options.body === 'string') {
        try {
          snapshot.orderRequest = JSON.parse(options.body);
        } catch (e) {}
      }

      localStorage.setItem(CHECKOUT_SNAPSHOT_KEY, JSON.stringify(snapshot));
    } catch (e) {}
  }

  function restoreCheckoutSnapshot(orderId) {
    let restored = false;

    try {
      const raw = localStorage.getItem(CHECKOUT_SNAPSHOT_KEY);
      if (!raw) {
        clearCheckoutSessionStorage(orderId, false);
        return false;
      }

      const snapshot = JSON.parse(raw);
      if (orderId && snapshot.orderId && snapshot.orderId !== orderId) {
        clearCheckoutSessionStorage(orderId, false);
        return false;
      }

      if (Array.isArray(snapshot.cartItems)) {
        localStorage.setItem('cartItems', JSON.stringify(snapshot.cartItems));
        restored = true;
      }
    } catch (e) {
      restored = false;
    }

    clearCheckoutSessionStorage(orderId, false);
    return restored;
  }

  async function cancelPendingPayment(orderId) {
    if (!orderId) return;

    try {
      const headers = { 'Content-Type': 'application/json' };
      try {
        const token = localStorage.getItem('token');
        if (token) headers.Authorization = 'Bearer ' + token;
      } catch (e) {}

      await fetch('/api/orders/cancel-payment', {
        method: 'POST',
        keepalive: true,
        credentials: 'include',
        headers,
        body: JSON.stringify({ order_id: orderId })
      });
    } catch (e) {
      console.warn('Ödeme iptali backend tarafında tamamlanamadı.', e);
    }
  }

  async function resetCancelledCheckout(orderId) {
    if (resetInProgress) return;
    resetInProgress = true;

    clearPolling();
    closePaymentChannel();
    hideWaitingOverlay();
    closeInlineCheckoutUi();
    inlinePaytrWasVisible = false;
    restoreCheckoutSnapshot(orderId);
    activeOrderId = '';
    paymentHandled = false;

    if (orderId) {
      cancelPendingPayment(orderId);
    } else {
      clearCheckoutSessionStorage('', false);
    }

    window.location.reload();

  }

  // ─── Bekleme Overlay ────────────────────────────────────────────────────────

  function createOverlayHTML() {
    return `
      <div id="paytr-waiting-overlay" style="
        position:fixed;inset:0;z-index:99999;
        background:rgba(15,23,42,0.82);backdrop-filter:blur(6px);
        display:flex;align-items:center;justify-content:center;
        font-family:Outfit,Segoe UI,Arial,sans-serif;
      ">
        <div style="
          background:#1e293b;border:1px solid rgba(255,255,255,0.12);
          border-radius:24px;padding:clamp(20px,5vw,36px) clamp(16px,5vw,32px);text-align:center;
          width:min(92vw,420px);box-shadow:0 24px 60px rgba(0,0,0,0.45);
          color:#f1f5f9;
        ">
          <div style="font-size:48px;margin-bottom:16px;">💳</div>
          <h2 style="margin:0 0 10px;font-size:22px;font-weight:700;">Ödeme Bekleniyor</h2>
          <p style="margin:0 0 20px;color:#94a3b8;font-size:15px;line-height:1.5;">
            Ödeme sayfası yeni sekmede açıldı.<br>
            İşlemi tamamladıktan sonra bu sayfa otomatik güncellenecek.
          </p>
          <div style="
            display:flex;align-items:center;justify-content:center;gap:10px;
            background:rgba(255,255,255,0.06);border-radius:12px;padding:12px 16px;
            margin-bottom:20px;font-size:14px;color:#cbd5e1;
          ">
            <span id="paytr-status-spinner" style="
              width:18px;height:18px;border:3px solid rgba(255,255,255,0.2);
              border-top-color:#6366f1;border-radius:50%;
              animation:paytr-spin 0.8s linear infinite;display:inline-block;
            "></span>
            <span id="paytr-status-text">Ödeme durumu kontrol ediliyor...</span>
          </div>
          <button id="paytr-reopen-btn" onclick="window.__paytrReopenTab && window.__paytrReopenTab()" style="
            background:rgba(99,102,241,0.18);border:1px solid rgba(99,102,241,0.4);
            color:#a5b4fc;border-radius:10px;padding:10px 18px;min-height:44px;
            font-size:14px;cursor:pointer;margin-bottom:10px;width:100%;
          ">Ödeme sekmesini tekrar aç</button>
          <button id="paytr-cancel-btn" onclick="window.__paytrCancelPayment && window.__paytrCancelPayment()" style="
            background:transparent;border:1px solid rgba(255,255,255,0.12);
            color:#94a3b8;border-radius:10px;padding:8px 18px;min-height:44px;
            font-size:13px;cursor:pointer;width:100%;
          ">İptal Et</button>
        </div>
        <style>
          @keyframes paytr-spin { to { transform:rotate(360deg); } }
        </style>
      </div>`;
  }

  function showWaitingOverlay(paymentUrl) {
    if (document.getElementById('paytr-waiting-overlay')) return;
    const div = document.createElement('div');
    div.innerHTML = createOverlayHTML();
    document.body.appendChild(div.firstElementChild);

    window.__paytrReopenTab = function() {
      window.open(paymentUrl, '_blank');
    };
  }

  function setOverlayStatus(text) {
    const el = document.getElementById('paytr-status-text');
    if (el) el.textContent = text;
  }

  function createJsonResponseLike(response, payload) {
    const headers = new Headers(response.headers || {});
    headers.set('content-type', 'application/json; charset=utf-8');
    return new Response(JSON.stringify(payload), {
      status: response.status,
      statusText: response.statusText,
      headers: headers
    });
  }

  function resolvePaymentUrl(payment, orderId, orderTotal) {
    if (!payment || typeof payment !== 'object') {
      return '';
    }

    if (payment.payment_url) {
      return payment.payment_url;
    }

    if (payment.iframe_token) {
      return 'https://www.paytr.com/odeme/guvenli/' + payment.iframe_token;
    }

    if (payment.mock) {
      return '/paytr-test.html?order_id=' + encodeURIComponent(orderId || '') + '&amount=' + encodeURIComponent(orderTotal || 0);
    }

    return '';
  }

  function hideWaitingOverlay() {
    const el = document.getElementById('paytr-waiting-overlay');
    if (el) el.remove();
    window.__paytrReopenTab = null;
    window.__paytrCancelPayment = null;
  }

  function closeInlineCheckoutUi() {
    try {
      document.querySelectorAll('.checkout-modal-backdrop').forEach(function(backdrop) {
        backdrop.style.display = 'none';
        backdrop.remove();
      });
      document.querySelectorAll('.checkout-modal-container, .checkout-modal-container.wide').forEach(function(modal) {
        modal.style.display = 'none';
      });
      document.querySelectorAll('.paytr-iframe-container, .paytr-iframe, iframe#paytriframe').forEach(function(node) {
        if (node && node.parentNode) node.parentNode.removeChild(node);
      });
      document.body.classList.remove('mobile-overlay-open');
      document.body.style.removeProperty('overflow');
      document.documentElement.style.removeProperty('overflow');
    } catch (e) {}

    inlinePaytrWasVisible = false;
  }

  // ─── BroadcastChannel ───────────────────────────────────────────────────────

  function openPaymentChannel(orderId) {
    if (paymentChannel) {
      try { paymentChannel.close(); } catch(e) {}
    }
    try {
      paymentChannel = new BroadcastChannel('paytr_payment_' + orderId);
      paymentChannel.onmessage = function(event) {
        const { status } = event.data || {};
        if (status === 'success' || status === 'paid') {
          handlePaymentSuccess(orderId);
        }
      };
    } catch(e) {
      console.warn('BroadcastChannel desteklenmiyor, sadece polling kullanılıyor.');
    }
  }

  function closePaymentChannel() {
    if (paymentChannel) {
      try { paymentChannel.close(); } catch(e) {}
      paymentChannel = null;
    }
  }

  // ─── localStorage fallback (sayfa yenilenmiş/overlay kapatılmış olsa da çalışır) ─

  function listenLocalStorageResult() {
    if (storageListenerBound) return;
    storageListenerBound = true;

    window.addEventListener('storage', function(e) {
      if (e.key !== 'paytr_result') return;
      try {
        var data = JSON.parse(e.newValue || '{}');
        // Yalnızca bu sekmenin aktif siparişine ait sonuçları işle
        if (data.orderId && data.orderId === activeOrderId) {
          if (data.status === 'success') {
            handlePaymentSuccess(data.orderId);
          }
        }
      } catch(err) {}
    });
  }

  // ─── Ödeme Sonucu İşleyiciler ───────────────────────────────────────────────

  function handlePaymentSuccess(orderId) {
    if (paymentHandled) return;
    paymentHandled = true;
    inlinePaytrWasVisible = false;
    clearPolling();
    closePaymentChannel();
    try {
      localStorage.removeItem(CHECKOUT_SNAPSHOT_KEY);
      localStorage.removeItem('paytr_result');
    } catch (e) {}
    setOverlayStatus('Ödeme onaylandı! Yönlendiriliyorsunuz...');
    setTimeout(function() {
      hideWaitingOverlay();
      window.location.href = '/order-success.html?order_id=' + orderId;
    }, 800);
  }

  function handlePaymentFailed(orderId) {
    if (paymentHandled) return;
    paymentHandled = true;
    inlinePaytrWasVisible = false;
    clearPolling();
    closePaymentChannel();
    hideWaitingOverlay();
    console.error('❌ ÖDEME BAŞARISIZ!', orderId);
    restoreCheckoutSnapshot(orderId);
    if (orderId) {
      cancelPendingPayment(orderId);
    }
    try {
      localStorage.removeItem('lastOrderId');
      localStorage.removeItem('lastOrderTotal');
    } catch (e) {}
    alert('Ödeme işlemi başarısız oldu. Sepetiniz geri yüklendi. Lütfen bilgileri yeniden girip tekrar deneyin.');
    window.location.reload();
  }

  // ─── Polling ────────────────────────────────────────────────────────────────

  function clearPolling() {
    if (paymentPollInterval) {
      clearInterval(paymentPollInterval);
      paymentPollInterval = null;
    }
  }

  function startPaymentPolling(orderId) {
    console.log('🔄 Ödeme durumu kontrol ediliyor...', orderId);
    if (!orderId) return;
    clearPolling();

    let attempts = 0;
    const maxAttempts = 200; // 200 × 3s = 10 dakika

    paymentPollInterval = setInterval(async function() {
      attempts++;

      if (attempts > maxAttempts) {
        clearPolling();
        closePaymentChannel();
        hideWaitingOverlay();
        alert('Ödeme işlemi zaman aşımına uğradı. Lütfen tekrar deneyin.');
        return;
      }

      try {
        const headers = {};
        try {
          const token = localStorage.getItem('token');
          if (token) headers.Authorization = 'Bearer ' + token;
        } catch(e) {}

        const response = await fetch(
          '/api/payments/paytr/status/' + orderId,
          { credentials: 'include', headers }
        );
        if (!response.ok) throw new Error('Status check failed');

        const data = await response.json();

        if (data.success) {
          const paymentStatus = (data.order && data.order.payment_status) || data.payment_status;
          console.log('💳 Ödeme durumu:', paymentStatus);

          if (paymentStatus === 'paid') {
            handlePaymentSuccess(orderId);
          }
        }
      } catch(error) {
        console.error('Polling error:', error);
      }
    }, 3000);
  }

  // ─── Ödeme Başlat ───────────────────────────────────────────────────────────

  function startPayment(paymentUrl, orderId, mode) {
    if (!paymentUrl || !orderId) return;
    activeOrderId = orderId;
    paymentHandled = false;
    inlinePaytrWasVisible = false;
    const isMobileViewport = window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : window.innerWidth <= 768;
    const openTopLevel = mode === 'top-level' || isMobileViewport;

    try {
      localStorage.setItem('lastOrderId', orderId);
      // Önceki sonuç varsa temizle
      localStorage.removeItem('paytr_result');
    } catch(e) {}

    // Banka 3D ekranları nested iframe içinde güvenilir çalışmıyor.
    // Bu yüzden top-level modda ödeme sayfasını mevcut sekmede aç.
    if (openTopLevel) {
      console.log('🌐 PayTR akışı üst sayfada açılıyor...', paymentUrl);
      window.location.assign(paymentUrl);
      return;
    }

    // 1) Yeni sekme aç
    window.open(paymentUrl, '_blank');

    // 2) İptal butonuna bağla
    window.__paytrCancelPayment = function() {
      resetCancelledCheckout(orderId);
    };

    // 3) Orijinal sekmede bekleme ekranı göster
    showWaitingOverlay(paymentUrl);

    // 4) BroadcastChannel dinle
    openPaymentChannel(orderId);

    // 5) localStorage storage event dinle (fallback)
    listenLocalStorageResult();

    // 6) Polling başlat
    startPaymentPolling(orderId);
  }

  // ─── Fetch Interceptor ──────────────────────────────────────────────────────

  const originalFetch = window.fetch;
  window.fetch = async function(...args) {
    const [url, options] = args;
    const response = await originalFetch.apply(this, args);

    if (url && (url.includes('/api/orders') || url.endsWith('/orders')) &&
        options && options.method === 'POST') {

      console.log('🛒 Sipariş oluşturma isteği yakalandı');
      const clonedResponse = response.clone();

      try {
        const data = await clonedResponse.json();
        console.log('📦 Sipariş yanıtı:', data);

        if (response.ok && data && data.payment) {
          const payment = data.payment;
          const orderId = data.id || data.order_id || (data.order && data.order.id) || payment.merchant_oid || '';
          const orderTotal = data.total || (data.order && data.order.total) || 0;
          const isMobileViewport = window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : window.innerWidth <= 768;
          activeOrderId = orderId;
          paymentHandled = false;
          inlinePaytrWasVisible = false;
          saveCheckoutSnapshot(orderId, options);

          // Sipariş tutarını order-success.html için sakla
          try {
            localStorage.setItem('lastOrderTotal', JSON.stringify({ orderId, total: orderTotal, ts: Date.now() }));
          } catch(e) {}

          const paymentUrl = resolvePaymentUrl(payment, orderId, orderTotal);
          if (!paymentUrl) {
            console.warn('⚠️ Payment objesi var ama token/url yok:', payment);
            return response;
          }

          console.log(isMobileViewport
            ? '📲 Mobil PayTR akışı üst sayfaya taşınıyor...'
            : '🖥️ Masaüstü PayTR akışı üst sayfaya taşınıyor...');

          setTimeout(function() {
            startPayment(paymentUrl, orderId, 'top-level');
          }, 25);

          return createJsonResponseLike(response, Object.assign({}, data, {
            payment: Object.assign({}, payment, {
              status: 'iframe_ready',
              iframe_token: '',
              payment_url: paymentUrl,
              opened_externally: true
            })
          }));

        } else if (response.ok && data && data.payment && data.payment.status === 'failed') {
          console.error('❌ PAYTR token alınamadı:', data.payment);
          alert(data.payment.message || 'PayTR ödeme oturumu başlatılamadı.');
        }
      } catch(error) {
        console.error('Sipariş yanıtı işlenirken hata:', error);
      }
    }

    return response;
  };

  console.log('✅ PayTR Helper Script hazır - top-level PayTR akışı aktif');

  window.PayTRHelper = {
    startPayment: startPayment,
    resetCancelledCheckout: resetCancelledCheckout,
    version: '2.5.0'
  };

  // Masaüstünde inline PayTR kapatılırsa eski oturumu bırakma.
  function handleInlinePaytrDismiss(event) {
    const target = event.target;
    if (!target || !target.closest) return;

    const hasInlinePaytr = document.querySelector(INLINE_PAYTR_SELECTOR);
    if (!hasInlinePaytr) return;

    const isBackdrop = target.classList && target.classList.contains('checkout-modal-backdrop');
    const closeButton = target.closest(INLINE_PAYTR_CLOSE_SELECTOR);
    if (!isBackdrop && !closeButton) return;

    const orderId = getRememberedOrderId();

    event.preventDefault();
    event.stopPropagation();
    resetCancelledCheckout(orderId);
  }

  document.addEventListener('pointerdown', handleInlinePaytrDismiss, true);
  document.addEventListener('click', handleInlinePaytrDismiss, true);

  if (window.MutationObserver && document.body) {
    new MutationObserver(function() {
      syncInlinePaytrState();
    }).observe(document.body, { childList: true, subtree: true, attributes: true });
  }

  window.addEventListener('pageshow', function() {
    syncInlinePaytrState();
  });

})();
