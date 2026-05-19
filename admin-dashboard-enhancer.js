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

  function isoDate(date) {
    return date.toISOString().slice(0, 10);
  }

  function defaultFrom() {
    var date = new Date();
    date.setDate(date.getDate() - 29);
    return isoDate(date);
  }

  function defaultTo() {
    return isoDate(new Date());
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

  function periodReportShell() {
    return [
      '<article class="bi-admin-panel bi-admin-period-report">',
      '<div class="bi-admin-panel-title"><h3>Raporlanabilir Satis Ozeti</h3><span>Tarih araligi</span></div>',
      '<form class="bi-admin-report-filter">',
      '<label><span>Baslangic</span><input type="date" name="from" value="', defaultFrom(), '"></label>',
      '<label><span>Bitis</span><input type="date" name="to" value="', defaultTo(), '"></label>',
      '<button type="submit">Raporu Getir</button>',
      '<button type="button" data-report-export>CSV Al</button>',
      '<button type="button" data-report-print>Yazdir</button>',
      '</form>',
      '<div class="bi-admin-period-result" data-report-result>',
      '<div class="bi-admin-empty">Secili tarih araligi icin rapor hazirlaniyor.</div>',
      '</div>',
      '</article>'
    ].join('');
  }

  function renderPeriodReport(report) {
    var target = document.querySelector('[data-report-result]');
    if (!target) return;
    var summary = report.summary || {};
    var daily = Array.isArray(report.daily) ? report.daily : [];
    target.innerHTML = [
      '<div class="bi-admin-period-kpis">',
      metricCard('Siparis', number(summary.total_orders), 'Secili donem', 'is-orders'),
      metricCard('Ciro', money(summary.revenue), 'Iptaller haric', 'is-revenue'),
      metricCard('Ortalama sepet', money(summary.avg_order_value), 'Siparis basina', ''),
      metricCard('Indirim', money(summary.total_discount), 'Toplam indirim', 'is-warning'),
      '</div>',
      '<div class="bi-admin-report-table-wrap"><table class="bi-admin-report-table">',
      '<thead><tr><th>Tarih</th><th>Siparis</th><th>Tamamlanan</th><th>Iptal</th><th>Ciro</th></tr></thead>',
      '<tbody>',
      daily.length ? daily.map(function (row) {
        return [
          '<tr><td>', row.day || '-', '</td><td>', number(row.total_orders), '</td><td>',
          number(row.completed), '</td><td>', number(row.cancelled), '</td><td>', money(row.revenue), '</td></tr>'
        ].join('');
      }).join('') : '<tr><td colspan="5">Bu aralikta veri yok.</td></tr>',
      '</tbody></table></div>'
    ].join('');
  }

  function csvEscape(value) {
    return '"' + String(value === null || value === undefined ? '' : value).replace(/"/g, '""') + '"';
  }

  function downloadPeriodCsv(report) {
    var daily = Array.isArray(report.daily) ? report.daily : [];
    var lines = [['Tarih', 'Siparis', 'Tamamlanan', 'Iptal', 'Ciro'].map(csvEscape).join(';')];
    daily.forEach(function (row) {
      lines.push([row.day, row.total_orders, row.completed, row.cancelled, row.revenue].map(csvEscape).join(';'));
    });
    var blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = 'boomeritems-satis-raporu.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  var lastPeriodReport = null;
  var periodLoading = false;

  function loadPeriodReport(shouldExport) {
    var form = document.querySelector('.bi-admin-report-filter');
    var result = document.querySelector('[data-report-result]');
    if (!form || periodLoading) return;
    var from = form.elements.from.value || defaultFrom();
    var to = form.elements.to.value || defaultTo();
    periodLoading = true;
    if (result) {
      result.innerHTML = '<div class="bi-admin-empty">Rapor yukleniyor...</div>';
    }
    api('/api/admin/stats/orders?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to))
      .then(function (report) {
        lastPeriodReport = report;
        renderPeriodReport(report);
        if (shouldExport) downloadPeriodCsv(report);
      })
      .catch(function () {
        if (result) {
          result.innerHTML = '<div class="bi-admin-empty">Rapor alinamadi. Tarihleri kontrol edin.</div>';
        }
      })
      .finally(function () {
        periodLoading = false;
      });
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
        'v3', revenue.total, revenue.this_month, orders.total, stock.alert_count, data.generated_at
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
      periodReportShell(),
      '</div>',
      '</section>'
    ].join('');

    if (existing) {
      var nextSignature = html.match(/data-report-signature="([^"]*)"/);
      if (nextSignature && existing.getAttribute('data-report-signature') === nextSignature[1]) {
        return;
      }
      existing.outerHTML = html;
      setTimeout(function () { loadPeriodReport(false); }, 0);
      return;
    }

    var header = dashboardView.querySelector('.admin-header');
    if (header) {
      header.insertAdjacentHTML('afterend', html);
      mounted = true;
      setTimeout(function () { loadPeriodReport(false); }, 0);
    }
  }

  function enhance() {
    var dashboardExists = document.querySelector('.admin-container .admin-header h1');
    var adminContainer = document.querySelector('.admin-container');
    document.body.classList.toggle('bi-admin-active', !!adminContainer);
    hydrateAdminTables();
    cleanSparePartsAdminNav();
    stabilizeInventorySummary();
    stabilizeAdminSelects();
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

  function hydrateAdminTables() {
    document.querySelectorAll('.admin-table').forEach(function (table) {
      if (table.dataset.biLabels === '1') return;
      var labels = Array.from(table.querySelectorAll('thead th')).map(function (th) {
        return (th.textContent || '').trim();
      });
      table.querySelectorAll('tbody tr').forEach(function (row) {
        Array.from(row.children).forEach(function (cell, index) {
          if (!cell.getAttribute('data-label')) {
            cell.setAttribute('data-label', labels[index] || 'Bilgi');
          }
        });
      });
      table.dataset.biLabels = '1';
    });
  }

  function cleanSparePartsAdminNav() {
    document.querySelectorAll('.admin-container .bi-spare-nav-link, .admin-container .bi-spare-category-link').forEach(function (node) {
      node.remove();
    });
    document.querySelectorAll('.admin-container a, .admin-container button, .admin-container div').forEach(function (node) {
      if (node.classList.contains('admin-container') || node.classList.contains('admin-sidebar') || node.classList.contains('admin-nav')) return;
      if (node.children.length > 2) return;
      if ((node.textContent || '').trim().toLowerCase().replace(/\s+/g, '').indexOf('yedekparca') !== -1) {
        node.remove();
      }
    });
  }

  function stabilizeInventorySummary() {
    document.querySelectorAll('.admin-container #adminInventoryShell').forEach(function (node) {
      node.setAttribute('aria-hidden', 'true');
      node.style.display = 'none';
    });
  }

  function stabilizeAdminSelects() {
    document.querySelectorAll('.admin-container select').forEach(function (select) {
      select.style.colorScheme = 'light';
      select.style.backgroundColor = '#ffffff';
      select.style.color = '#101828';
      select.style.webkitTextFillColor = '#101828';
      Array.from(select.options || []).forEach(function (option) {
        option.style.colorScheme = 'light';
        option.style.backgroundColor = '#ffffff';
        option.style.color = '#101828';
        option.style.webkitTextFillColor = '#101828';
      });
    });
  }

  document.addEventListener('pointerdown', function (event) {
    var select = event.target && event.target.closest ? event.target.closest('.admin-container select') : null;
    if (!select) return;
    stabilizeAdminSelects();
  }, true);

  document.addEventListener('focusin', function (event) {
    if (event.target && event.target.matches && event.target.matches('.admin-container select')) {
      stabilizeAdminSelects();
    }
  }, true);

  document.addEventListener('submit', function (event) {
    if (!event.target || !event.target.classList.contains('bi-admin-report-filter')) return;
    event.preventDefault();
    loadPeriodReport(false);
  });

  document.addEventListener('click', function (event) {
    if (event.target && event.target.closest('[data-report-export]')) {
      if (lastPeriodReport) {
        downloadPeriodCsv(lastPeriodReport);
      } else {
        loadPeriodReport(true);
      }
    }
    if (event.target && event.target.closest('[data-report-print]')) {
      window.print();
    }
  });

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
