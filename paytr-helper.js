/**
 * PayTR Ödeme Helper Script
 * BoomerItems için PayTR iframe entegrasyonu
 *
 * Bu script mevcut checkout akışını dinler ve PayTR iframe'ini açar
 */

(function() {
  'use strict';

  console.log('🚀 PayTR Helper Script yüklendi');
  let activeIframeToken = '';
  let activeOrderId = '';
  let paymentPollInterval = null;

  // PayTR iframe akışını izle
  function openPaytrIframe(iframeToken, orderId) {
    console.log('📱 PayTR iframe açılıyor...', { iframeToken, orderId });

    if (iframeToken && activeIframeToken === iframeToken) {
      console.log('ℹ️ Aynı PayTR token zaten açık, ikinci açma atlandı.');
      return;
    }

    activeIframeToken = iframeToken || '';
    activeOrderId = orderId || activeOrderId || '';

    // Ödeme durumunu polling ile kontrol et
    startPaymentPolling(activeOrderId);
  }

  // Ödeme durumu polling
  function startPaymentPolling(orderId) {
    console.log('🔄 Ödeme durumu kontrol ediliyor...', orderId);

    if (!orderId) {
      console.warn('⚠️ PayTR polling başlatılamadı: orderId yok.');
      return;
    }

    if (paymentPollInterval) {
      clearInterval(paymentPollInterval);
      paymentPollInterval = null;
    }

    let attempts = 0;
    const maxAttempts = 200; // 200 * 3s = 10 dakika

    paymentPollInterval = setInterval(async function() {
      attempts++;

      if (attempts > maxAttempts) {
        clearInterval(paymentPollInterval);
        paymentPollInterval = null;
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
            clearInterval(paymentPollInterval);
            paymentPollInterval = null;
            closePaytrModal();

            console.log('✅ ÖDEME BAŞARILI!', orderId);

            // Başarı sayfasına yönlendir
            window.location.href = `/order-success.html?order_id=${orderId}`;

          } else if (paymentStatus === 'failed') {
            // ❌ ÖDEME BAŞARISIZ
            clearInterval(paymentPollInterval);
            paymentPollInterval = null;
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
    if (paymentPollInterval) {
      clearInterval(paymentPollInterval);
      paymentPollInterval = null;
    }
    activeIframeToken = '';
    activeOrderId = '';
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

            try {
              if (orderId) localStorage.setItem('lastOrderId', orderId);
            } catch (e) {}

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
