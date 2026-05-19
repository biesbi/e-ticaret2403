(function () {
  var mounted = false;
  var lastFetch = 0;
  var cachedDashboard = null;

  function token() {
    try {
      return localStorage.getItem('token') || '';
    } catch (error) {
      return '';
    }
  }

  function money(value) {
    var amount = Number(value || 0);
    return amount.toLocaleString('tr-TR', {
      maximumFractionDigits: 0
    }) + ' TL';
  }

  function number(value) {
    return Number(value || 0).toLocaleString('tr-TR');
  }

  function pct(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
      return 'Yeni veri';
    }
    var sign = Number(value) > 0 ? '+' : '';
    return sign + Number(value).toLocaleString('tr-TR', { maximumFractionDigits: 1 }) + '%';
  }

  function statusLabel(status) {
    var labels = {
      pending: 'Bekleyen',
      processing: 'Hazırlanıyor',
      preparing: 'Hazırlanıyor',
      confirmed: 'Onaylandı',
      paid: 'Ödendi',
      shipped: 'Kargoda',
      delivered: 'Teslim',
      cancelled: 'İptal',
      failed: 'Başarısız'
    };
    return labels[status] || status || 'Bilinmiyor';
  }

  function api(path) {
    return fetch(path, {
      headers: {
        Authorization: 'Bearer ' + token(),
        Accept: 'application/json'
      }
    }).then(function (response) {
      if (!response.ok) throw new Error('API ' + response.status);
      return response.json();
    });
  }

  function metricCard(label, value, note, tone) {
    return [
      '<article class="bi-admin-kpi ', tone || '', '">',
      '<span>', label, '</span>',
      '<strong>', value, '</strong>',
      '<small>', note || '', '</small>',
      '</article>'
    ].join('');
  }

  function miniBars(trend) {
    var days = Array.isArray(trend) ? trend.slice(-14) : [];
    if (!days.length) {
      return '<div class="bi-admin-empty">Son 14 gün için gelir hareketi yok.</div>';
    }
    var max = Math.max.apply(null, days.map(function (row) {
      return Number(row.revenue || 0);
    })) || 1;

    return [
      '<div class="bi-admin-bars">',
      days.map(function (row) {
        var height = Math.max(10, Math.round((Number(row.revenue || 0) / max) * 100));
        return [
          '<span title="', row.day, ' - ', money(row.revenue), '">',
          '<i style="height:', height, '%"></i>',
          '</span>'
        ].join('');
      }).join(''),
      '</div>'
    ].join('');
  }

  function statusBreakdown(byStatus, total) {
    var entries = Object.keys(byStatus || {}).map(function (key) {
      return [key, Number(byStatus[key] || 0)];
    }).sort(function (a, b) {
      return b[1] - a[1];
    });
    if (!entries.length) {
      return '<div class="bi-admin-empty">Sipariş durum verisi yok.</div>';
    }
    return entries.map(function (entry) {
      var width = total > 0 ? Math.round((entry[1] / total) * 100) : 0;
      return [
        '<div class="bi-admin-status-row">',
        '<div><strong>', statusLabel(entry[0]), '</strong><span>', number(entry[1]), ' sipariş</span></div>',
        '<em>', width, '%</em>',
        '<b><i style="width:', width, '%"></i></b>',
        '</div>'
      ].join('');
    }).join('');
  }

  function listRows(items, type) {
    var rows = Array.isArray(items) ? items.slice(0, 5) : [];
    if (!rows.length) {
      return '<div class="bi-admin-empty">Gösterilecek kayıt yok.</div>';
    }
    return rows.map(function (item) {
      if (type === 'stock') {
        return [
          '<li><div><strong>', item.name || 'Ürün', '</strong><span>',
          item.category || 'Kategori yok', '</span></div><b>', number(item.stock), ' stok</b></li>'
        ].join('');
      }
      if (type === 'customer') {
        return [
          '<li><div><strong>', item.display_name || item.email || 'Müşteri', '</strong><span>',
          number(item.order_count), ' sipariş</span></div><b>', money(item.total_spent), '</b></li>'
        ].join('');
      }
      return [
        '<li><div><strong>', item.name || 'Ürün', '</strong><span>',
        money(item.price), '</span></div><b>', number(item.sold), ' satış</b></li>'
      ].join('');
    }).join('');
  }

  function render(data) {
    var dashboardView = Array.from(document.querySelectorAll('.admin-view')).find(function (view) {
      var title = view.querySelector('.admin-header h1');
      return title && /Yönetim Paneli|Yonetim Paneli/i.test(title.textContent || '');
    });
    if (!dashboardView) return;

    var existing = dashboardView.querySelector('.bi-admin-reporting');
    var revenue = data.revenue || {};
    var orders = data.orders || {};
    var products = data.products || {};
    var users = data.users || {};
    var stock = data.stock || {};
    var totalOrders = Number(orders.total || 0);

    var html = [
      '<section class="bi-admin-reporting" data-report-signature="', [
        revenue.total, revenue.this_month, orders.total, stock.alert_count, data.generated_at
      ].join('|'), '" aria-label="Rapor ozeti">',
      '<div class="bi-admin-report-head">',
      '<div><span>Canlı rapor</span><h2>Performans Özeti</h2></div>',
      '<time>', data.generated_at ? new Date(data.generated_at.replace(' ', 'T')).toLocaleString('tr-TR') : 'Güncel', '</time>',
      '</div>',
      '<div class="bi-admin-kpi-grid">',
      metricCard('Bugünkü gelir', money(revenue.today), 'Bu hafta ' + money(revenue.this_week), 'is-revenue'),
      metricCard('Bu ay gelir', money(revenue.this_month), 'Geçen aya göre ' + pct(revenue.month_growth_pct), 'is-revenue'),
      metricCard('Bugünkü sipariş', number(orders.today), 'Bu ay ' + number(orders.this_month) + ' sipariş', 'is-orders'),
      metricCard('Stok alarmı', number(stock.alert_count), number(products.low_stock) + ' düşük, ' + number(products.out_of_stock) + ' tükendi', 'is-warning'),
      '</div>',
      '<div class="bi-admin-report-grid">',
      '<article class="bi-admin-panel bi-admin-panel-wide"><div class="bi-admin-panel-title"><h3>14 Günlük Gelir Akışı</h3><span>', money(revenue.total), ' toplam</span></div>',
      miniBars(revenue.daily_trend),
      '</article>',
      '<article class="bi-admin-panel"><div class="bi-admin-panel-title"><h3>Sipariş Durumları</h3><span>', number(totalOrders), ' toplam</span></div>',
      statusBreakdown(orders.by_status, totalOrders),
      '</article>',
      '<article class="bi-admin-panel"><div class="bi-admin-panel-title"><h3>Çok Satanlar</h3><span>İlk 5</span></div><ul class="bi-admin-list">',
      listRows(products.top_selling, 'product'),
      '</ul></article>',
      '<article class="bi-admin-panel"><div class="bi-admin-panel-title"><h3>Aktif Müşteriler</h3><span>Harcama</span></div><ul class="bi-admin-list">',
      listRows(users.top_customers, 'customer'),
      '</ul></article>',
      '<article class="bi-admin-panel"><div class="bi-admin-panel-title"><h3>Stok Takibi</h3><span>Acil</span></div><ul class="bi-admin-list">',
      listRows([].concat(stock.out_of_stock || [], stock.low_stock || []), 'stock'),
      '</ul></article>',
      '</div>',
      '</section>'
    ].join('');

    if (existing) {
      var nextSignature = html.match(/data-report-signature="([^"]*)"/);
      if (nextSignature && existing.getAttribute('data-report-signature') === nextSignature[1]) {
        return;
      }
      existing.outerHTML = html;
      return;
    }

    var header = dashboardView.querySelector('.admin-header');
    if (header) {
      header.insertAdjacentHTML('afterend', html);
      mounted = true;
    }
  }

  function enhance() {
    var dashboardExists = document.querySelector('.admin-container .admin-header h1');
    if (!dashboardExists) return;

    if (cachedDashboard && Date.now() - lastFetch < 60000) {
      render(cachedDashboard);
      return;
    }

    if (!token()) return;
    api('/api/admin/dashboard')
      .then(function (data) {
        cachedDashboard = data;
        lastFetch = Date.now();
        render(data);
      })
      .catch(function () {
        if (!mounted) {
          var view = document.querySelector('.admin-view');
          if (view && !view.querySelector('.bi-admin-reporting-error')) {
            view.insertAdjacentHTML('afterbegin', '<div class="bi-admin-reporting-error">Rapor verileri şu an alınamadı.</div>');
          }
        }
      });
  }

  function boot() {
    enhance();
    if (window.MutationObserver) {
      new MutationObserver(function () {
        window.requestAnimationFrame(enhance);
      }).observe(document.body, { childList: true, subtree: true });
    }
    setInterval(enhance, 60000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
