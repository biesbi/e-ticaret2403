/**
 * PayTR Ödeme Helper Script
 * BoomerItems için PayTR yeni sekme entegrasyonu
 *
 * Ödeme yeni bir sekmede açılır; orijinal sekme polling + BroadcastChannel
 * ile sonucu takip eder ve ödeme tamamlanınca yönlendirme yapar.
 */

(function() {
  'use strict';

  console.log('🚀 PayTR Helper Script yüklendi');
  let activeOrderId = '';
  let paymentPollInterval = null;
  let paymentChannel = null;

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
          border-radius:24px;padding:36px 32px;text-align:center;
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
            color:#a5b4fc;border-radius:10px;padding:10px 18px;
            font-size:14px;cursor:pointer;margin-bottom:10px;width:100%;
          ">Ödeme sekmesini tekrar aç</button>
          <button id="paytr-cancel-btn" onclick="window.__paytrCancelPayment && window.__paytrCancelPayment()" style="
            background:transparent;border:1px solid rgba(255,255,255,0.12);
            color:#94a3b8;border-radius:10px;padding:8px 18px;
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

    // "Tekrar aç" butonu için URL sakla
    window.__paytrReopenTab = function() {
      window.open(paymentUrl, '_blank');
    };
  }

  function setOverlayStatus(text) {
    const el = document.getElementById('paytr-status-text');
    if (el) el.textContent = text;
  }

  function hideWaitingOverlay() {
    const el = document.getElementById('paytr-waiting-overlay');
    if (el) el.remove();
    window.__paytrReopenTab = null;
    window.__paytrCancelPayment = null;
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
        } else if (status === 'failed') {
          handlePaymentFailed(orderId);
        }
      };
    } catch(e) {
      // BroadcastChannel desteklenmiyor (IE) - polling yeterli
      console.warn('BroadcastChannel desteklenmiyor, sadece polling kullanılıyor.');
    }
  }

  function closePaymentChannel() {
    if (paymentChannel) {
      try { paymentChannel.close(); } catch(e) {}
      paymentChannel = null;
    }
  }

  // ─── Ödeme Sonucu İşleyiciler ───────────────────────────────────────────────

  function handlePaymentSuccess(orderId) {
    if (!document.getElementById('paytr-waiting-overlay')) return; // zaten işlendi
    clearPolling();
    closePaymentChannel();
    setOverlayStatus('Ödeme onaylandı! Yönlendiriliyorsunuz...');
    setTimeout(function() {
      hideWaitingOverlay();
      window.location.href = '/order-success.html?order_id=' + orderId;
    }, 800);
  }

  function handlePaymentFailed(orderId) {
    if (!document.getElementById('paytr-waiting-overlay')) return;
    clearPolling();
    closePaymentChannel();
    hideWaitingOverlay();
    console.error('❌ ÖDEME BAŞARISIZ!', orderId);
    alert('Ödeme işlemi başarısız oldu. Lütfen tekrar deneyin.');
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
          'https://www.boomeritems.com/api/payments/paytr/status/' + orderId,
          { credentials: 'include', headers }
        );
        if (!response.ok) throw new Error('Status check failed');

        const data = await response.json();

        if (data.success) {
          const paymentStatus = (data.order && data.order.payment_status) || data.payment_status;
          console.log('💳 Ödeme durumu:', paymentStatus);

          if (paymentStatus === 'paid') {
            handlePaymentSuccess(orderId);
          } else if (paymentStatus === 'failed') {
            handlePaymentFailed(orderId);
          }
        }
      } catch(error) {
        console.error('Polling error:', error);
      }
    }, 3000);
  }

  // ─── Ödeme Başlat ───────────────────────────────────────────────────────────

  function startPayment(paymentUrl, orderId) {
    if (!paymentUrl || !orderId) return;
    activeOrderId = orderId;

    try { localStorage.setItem('lastOrderId', orderId); } catch(e) {}

    // 1) Yeni sekme aç
    window.open(paymentUrl, '_blank');

    // 2) İptal butonuna bağla
    window.__paytrCancelPayment = function() {
      clearPolling();
      closePaymentChannel();
      hideWaitingOverlay();
    };

    // 3) Orijinal sekmede bekleme ekranı göster
    showWaitingOverlay(paymentUrl);

    // 4) BroadcastChannel dinle (yeni sekme haber verir)
    openPaymentChannel(orderId);

    // 5) Polling başlat
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

          if (payment.iframe_token) {
            // Gerçek PayTR iframe → yeni sekme
            const paymentUrl = 'https://www.paytr.com/odeme/guvenli/' + payment.iframe_token;
            console.log('💳 PayTR iframe token bulundu, yeni sekme açılıyor...');
            setTimeout(() => startPayment(paymentUrl, orderId), 500);

          } else if (payment.payment_url) {
            // Alternatif URL → yeni sekme
            console.log('🔗 Payment URL bulundu:', payment.payment_url);
            setTimeout(() => startPayment(payment.payment_url, orderId), 500);

          } else if (payment.mock) {
            // Mock/test modu → yeni sekme
            const amount = data.total || 0;
            const mockUrl = '/paytr-test.html?order_id=' + orderId + '&amount=' + amount;
            console.log('🧪 Mock mode - yeni sekme açılıyor...');
            setTimeout(() => startPayment(mockUrl, orderId), 500);

          } else {
            console.warn('⚠️ Payment objesi var ama token/url yok:', payment);
          }

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

  console.log('✅ PayTR Helper Script hazır - Yeni Sekme modu aktif');

  window.PayTRHelper = {
    startPayment: startPayment,
    version: '2.0.0'
  };

})();
