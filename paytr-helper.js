/**
 * PayTR Ödeme Helper Script
 * BoomerItems için PayTR iframe entegrasyonu
 *
 * Bu script mevcut checkout akışını dinler ve PayTR iframe'ini açar
 */

(function() {
  'use strict';

  console.log('🚀 PayTR Helper Script yüklendi');

  // PayTR iframe modal HTML
  const MODAL_HTML = `
    <div id="paytr-modal-overlay" style="
      display: none;
      position: fixed;
      inset: 0;
      z-index: 999999;
      background: rgba(0, 0, 0, 0.85);
      backdrop-filter: blur(4px);
      animation: fadeIn 0.3s ease;
    ">
      <div id="paytr-modal-container" style="
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 95%;
        max-width: 900px;
        height: 90vh;
        max-height: 800px;
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        animation: slideUp 0.3s ease;
      ">
        <div id="paytr-modal-header" style="
          position: absolute;
          top: 0;
          left: 0;
          right: 0;
          z-index: 10;
          padding: 16px 20px;
          background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
          display: flex;
          justify-content: space-between;
          align-items: center;
        ">
          <div style="color: white; font-weight: 700; font-size: 18px;">
            🔒 Güvenli Ödeme - PayTR
          </div>
          <button id="paytr-close-btn" style="
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid white;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
          " onmouseover="this.style.background='rgba(255,255,255,0.3)'"
             onmouseout="this.style.background='rgba(255,255,255,0.2)'">
            ✕ Kapat
          </button>
        </div>
        <div id="paytr-iframe-wrapper" style="
          width: 100%;
          height: 100%;
          padding-top: 64px;
        ">
          <div id="paytr-loading" style="
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #666;
          ">
            <div style="
              width: 50px;
              height: 50px;
              border: 4px solid #f3f3f3;
              border-top: 4px solid #667eea;
              border-radius: 50%;
              animation: spin 1s linear infinite;
            "></div>
            <p style="margin-top: 20px; font-size: 16px; font-weight: 600;">
              Ödeme sayfası yükleniyor...
            </p>
          </div>
        </div>
      </div>
    </div>
    <style>
      @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
      }
      @keyframes slideUp {
        from {
          opacity: 0;
          transform: translate(-50%, -45%);
        }
        to {
          opacity: 1;
          transform: translate(-50%, -50%);
        }
      }
      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }
    </style>
  `;

  // Modal'ı DOM'a ekle
  function initModal() {
    if (!document.getElementById('paytr-modal-overlay')) {
      const div = document.createElement('div');
      div.innerHTML = MODAL_HTML;
      document.body.appendChild(div.firstElementChild);

      // Kapat butonu
      document.getElementById('paytr-close-btn').addEventListener('click', function() {
        if (confirm('Ödemeyi iptal etmek istediğinizden emin misiniz?')) {
          closePaytrModal();
        }
      });
    }
  }

  // PayTR iframe'ini aç
  function openPaytrIframe(iframeToken, orderId) {
    console.log('📱 PayTR iframe açılıyor...', { iframeToken, orderId });

    initModal();

    const overlay = document.getElementById('paytr-modal-overlay');
    const wrapper = document.getElementById('paytr-iframe-wrapper');
    const loading = document.getElementById('paytr-loading');

    // Overlay'i göster
    overlay.style.display = 'block';
    document.body.style.overflow = 'hidden';

    // Iframe oluştur
    const iframe = document.createElement('iframe');
    iframe.id = 'paytriframe';
    iframe.name = 'paytriframe';
    iframe.src = `https://www.paytr.com/odeme/guvenli/${iframeToken}`;
    iframe.frameBorder = '0';
    iframe.scrolling = 'yes';
    iframe.style.cssText = 'width: 100%; height: 100%; border: none;';

    iframe.onload = function() {
      console.log('✅ PayTR iframe yüklendi');
      if (loading) loading.style.display = 'none';
    };

    // Loading'i kaldır ve iframe'i ekle
    setTimeout(() => {
      wrapper.innerHTML = '';
      wrapper.appendChild(iframe);
    }, 500);

    // Ödeme durumunu polling ile kontrol et
    startPaymentPolling(orderId);
  }

  // Ödeme durumu polling
  function startPaymentPolling(orderId) {
    console.log('🔄 Ödeme durumu kontrol ediliyor...', orderId);

    let attempts = 0;
    const maxAttempts = 200; // 200 * 3s = 10 dakika

    const pollInterval = setInterval(async function() {
      attempts++;

      if (attempts > maxAttempts) {
        clearInterval(pollInterval);
        console.warn('⏱️ Ödeme zaman aşımı');
        closePaytrModal();
        alert('Ödeme işlemi zaman aşımına uğradı. Lütfen tekrar deneyin.');
        return;
      }

      try {
        const headers = {};
        try {
          const token = localStorage.getItem('token');
          if (token) headers.Authorization = `Bearer ${token}`;
        } catch (e) {}

        const response = await fetch(`https://www.boomeritems.com/api/payments/paytr/status/${orderId}`, {
          credentials: 'include',
          headers
        });

        if (!response.ok) {
          throw new Error('Status check failed');
        }

        const data = await response.json();

        if (data.success) {
          const paymentStatus = data.order?.payment_status || data.payment_status;

          console.log('💳 Ödeme durumu:', paymentStatus);

          if (paymentStatus === 'paid') {
            // ✅ ÖDEME BAŞARILI
            clearInterval(pollInterval);
            closePaytrModal();

            console.log('✅ ÖDEME BAŞARILI!', orderId);

            // Başarı sayfasına yönlendir
            window.location.href = `/order-success.html?order_id=${orderId}`;

          } else if (paymentStatus === 'failed') {
            // ❌ ÖDEME BAŞARISIZ
            clearInterval(pollInterval);
            closePaytrModal();

            console.error('❌ ÖDEME BAŞARISIZ!', orderId);

            alert('Ödeme işlemi başarısız oldu. Lütfen tekrar deneyin.');
            // Kullanıcı checkout'ta kalsın, tekrar denesin
          }
        }
      } catch (error) {
        console.error('Polling error:', error);
      }
    }, 3000); // Her 3 saniyede bir kontrol
  }

  // Modal'ı kapat
  function closePaytrModal() {
    const overlay = document.getElementById('paytr-modal-overlay');
    if (overlay) {
      overlay.style.display = 'none';
      document.body.style.overflow = '';
    }
  }

  // Fetch API'yi intercept et (sipariş oluşturma isteğini yakala)
  const originalFetch = window.fetch;
  window.fetch = async function(...args) {
    const [url, options] = args;
    const response = await originalFetch.apply(this, args);

    // Sipariş oluşturma isteği mi?
    if (url && (url.includes('/api/orders') || url.endsWith('/orders')) &&
        options && options.method === 'POST') {

      console.log('🛒 Sipariş oluşturma isteği yakalandı');

      // Response'u klonla (bir kere okunabilir)
      const clonedResponse = response.clone();

      try {
        const data = await clonedResponse.json();

        console.log('📦 Sipariş yanıtı:', data);
        console.log('🔍 Payment objesi:', data.payment);

        // PayTR ödeme bilgisi var mı?
        if (response.ok && data && data.payment) {
          console.log('💰 Payment bilgisi bulundu:', data.payment);

          if (data.payment.iframe_token) {
            const { iframe_token, merchant_oid } = data.payment;
            const orderId = data.id || data.order_id || (data.order && data.order.id) || merchant_oid;

            console.log('💳 PayTR iframe token bulundu, modal açılıyor...');
            console.log('Token:', iframe_token);
            console.log('Order ID:', orderId);

            // Modal'ı aç
            setTimeout(() => {
              openPaytrIframe(iframe_token, orderId);
            }, 500);
          } else if (data.payment.payment_url) {
            // Alternatif: payment_url varsa onu kullan
            const orderId = data.id || data.order_id || (data.order && data.order.id) || '';
            console.log('🔗 Payment URL bulundu:', data.payment.payment_url);

            setTimeout(() => {
              window.open(data.payment.payment_url, 'paytr_payment', 'width=800,height=700');
              startPaymentPolling(orderId);
            }, 500);
          } else if (data.payment.mock) {
            // Mock mode - test sayfasını aç
            const orderId = data.id || data.order_id || (data.order && data.order.id) || '';
            const amount = data.total || 0;

            console.log('🧪 Mock mode - test sayfası açılıyor...');

            setTimeout(() => {
              window.open(
                `/paytr-test.html?order_id=${orderId}&amount=${amount}`,
                'paytr_test',
                'width=600,height=700'
              );

              // Polling başlat
              startPaymentPolling(orderId);
            }, 500);
          } else {
            console.warn('⚠️ Payment objesi var ama iframe_token veya payment_url yok:', data.payment);
          }
        } else if (response.ok && data && data.payment && data.payment.status === 'failed') {
          console.error('❌ PAYTR token alınamadı:', data.payment);
          alert(data.payment.message || 'PayTR ödeme oturumu başlatılamadı.');
        } else if (response.ok && data && data.id) {
          console.warn('⚠️ Sipariş başarılı ama payment bilgisi yok! Order ID:', data.id);
          console.log('Full response:', data);
        }
      } catch (error) {
        console.error('Sipariş yanıtı işlenirken hata:', error);
      }
    }

    return response;
  };

  console.log('✅ PayTR Helper Script hazır - Fetch interceptor aktif');

  // Global erişim için
  window.PayTRHelper = {
    openIframe: openPaytrIframe,
    closeModal: closePaytrModal,
    version: '1.0.0'
  };

})();
