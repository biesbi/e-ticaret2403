const fs = require('fs');
const fpath = 'c:\\xampp\\htdocs\\collector.js';
let content = fs.readFileSync(fpath, 'utf8');

const startMarker = '// \u2500\u2500\u2500 PARTS';
const endMarker = 'function adjustStock(id, delta) {';

const startIdx = content.indexOf(startMarker);
const endIdx = content.indexOf(endMarker);

if (startIdx < 0 || endIdx < 0) {
  // Try alternate marker
  const alt = '// --- PARTS';
  const altIdx = content.indexOf(alt);
  if (altIdx >= 0) {
    console.log('Found alternate marker at', altIdx);
  }
  console.log('startIdx=' + startIdx + ' endIdx=' + endIdx);
  // List some context
  const lines = content.split('\n');
  for (let i = 518; i < 530 && i < lines.length; i++) {
    console.log(i + ': ' + lines[i].substring(0, 80));
  }
  process.exit(1);
}

const before = content.substring(0, startIdx);
const after = content.substring(endIdx);

const newCode = String.raw`// --- PARTS ----------------------------------------------------------------
var COLOR_MAP = {
  'kirmizi':'#ef4444','red':'#ef4444','mavi':'#3b82f6','blue':'#3b82f6',
  'yesil':'#22c55e','green':'#22c55e','sari':'#facc15','yellow':'#facc15',
  'beyaz':'#e8eaf0','white':'#e8eaf0','siyah':'#1e1e1e','black':'#1e1e1e',
  'gri':'#6b7280','gray':'#6b7280','grey':'#6b7280','turuncu':'#f97316','orange':'#f97316',
  'mor':'#a855f7','purple':'#a855f7','kahverengi':'#92400e','brown':'#92400e',
  'pembe':'#ec4899','pink':'#ec4899'
};

function renderParts() {
  var allParts = getParts();
  var search = (document.getElementById('partsSearch') ? document.getElementById('partsSearch').value : '').toLowerCase();
  var filter = document.getElementById('partsFilter') ? document.getElementById('partsFilter').value : 'all';
  var condFilt = document.getElementById('partsCondFilter') ? document.getElementById('partsCondFilter').value : 'all';
  var sortVal = document.getElementById('partsSort') ? document.getElementById('partsSort').value : 'name';
  var grid = document.getElementById('partsGrid');
  var statsBar = document.getElementById('partsStatsBar');
  if (!grid) return;
  var totalStock = 0, lowStock = 0, outOfStock = 0;
  for (var si = 0; si < allParts.length; si++) {
    totalStock += (allParts[si].stock || 0);
    if (allParts[si].stock > 0 && allParts[si].stock <= 5) lowStock++;
    if (allParts[si].stock <= 0 || allParts[si].condition === 'missing') outOfStock++;
  }
  if (statsBar) {
    statsBar.innerHTML = '<div class="parts-stat-card" style="--_glow:rgba(59,130,246,0.08)"><div class="parts-stat-icon psi-total">\uD83D\uDCE6</div><div><div class="parts-stat-value">'+allParts.length+'</div><div class="parts-stat-label">Toplam Par\u00E7a \u00C7e\u015Fidi</div></div></div><div class="parts-stat-card" style="--_glow:rgba(34,197,94,0.08)"><div class="parts-stat-icon psi-stock">\u2714</div><div><div class="parts-stat-value">'+totalStock+'</div><div class="parts-stat-label">Toplam Stok Adedi</div></div></div><div class="parts-stat-card" style="--_glow:rgba(245,158,11,0.08)"><div class="parts-stat-icon psi-low">\u26A0</div><div><div class="parts-stat-value">'+lowStock+'</div><div class="parts-stat-label">Azalan Stok</div></div></div><div class="parts-stat-card" style="--_glow:rgba(248,113,113,0.08)"><div class="parts-stat-icon psi-missing">\u2718</div><div><div class="parts-stat-value">'+outOfStock+'</div><div class="parts-stat-label">Stokta Yok / Eksik</div></div></div>';
  }
  var items = [];
  for (var fi = 0; fi < allParts.length; fi++) {
    var fp = allParts[fi];
    if (search && fp.name.toLowerCase().indexOf(search)<0 && fp.partNo.toLowerCase().indexOf(search)<0 && (fp.color||'').toLowerCase().indexOf(search)<0) continue;
    if (filter !== 'all' && fp.category !== filter) continue;
    if (condFilt !== 'all' && fp.condition !== condFilt) continue;
    items.push(fp);
  }
  if (sortVal === 'name') items.sort(function(a,b){return a.name.localeCompare(b.name,'tr');});
  if (sortVal === 'stock_high') items.sort(function(a,b){return (b.stock||0)-(a.stock||0);});
  if (sortVal === 'stock_low') items.sort(function(a,b){return (a.stock||0)-(b.stock||0);});
  if (sortVal === 'partno') items.sort(function(a,b){return a.partNo.localeCompare(b.partNo);});
  if (items.length === 0) {
    var et = (search||filter!=='all'||condFilt!=='all') ? 'Sonu\u00E7 bulunamad\u0131' : 'Hen\u00FCz par\u00E7a yok';
    var ed = (search||filter!=='all'||condFilt!=='all') ? 'Farkl\u0131 filtre veya arama deneyin' : '+ Par\u00E7a Ekle butonuyla ilk par\u00E7an\u0131z\u0131 ekleyin';
    grid.innerHTML = '<div class="parts-empty"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="3"/></svg><h3>'+et+'</h3><p>'+ed+'</p></div>';
    return;
  }
  var html = '';
  for (var ci = 0; ci < items.length; ci++) {
    var p = items[ci];
    var sClass = p.stock > 5 ? 'stock-ok' : p.stock > 0 ? 'stock-warn' : 'stock-zero';
    var ssClass = p.stock > 5 ? 'pss-ok' : p.stock > 0 ? 'pss-low' : 'pss-out';
    var ssText = p.stock > 5 ? '\u2714 Stokta' : p.stock > 0 ? '\u26A0 Azal\u0131yor' : '\u2718 Stok Yok';
    var cHex = COLOR_MAP[(p.color||'').toLowerCase()] || '#6b7280';
    var miss = p.condition === 'missing' || p.stock <= 0;
    html += '<div class="part-card'+(miss?' part-missing':'')+'">';
    html += '<div class="part-card-header"><span class="part-card-id">#'+p.partNo+'</span><span class="part-card-cond pcond-'+p.condition+'">'+(PART_COND_LABEL[p.condition]||p.condition)+'</span></div>';
    html += '<div class="part-card-body"><div class="part-card-name">'+p.name+'</div><div class="part-card-meta"><span class="part-meta-tag">'+(PART_CAT_LABEL[p.category]||p.category)+'</span>';
    if (p.color) html += '<span class="part-meta-color"><span class="part-color-dot" style="background:'+cHex+'"></span>'+p.color+'</span>';
    html += '</div></div>';
    html += '<div class="part-card-stock"><div class="part-stock-display"><div class="part-stock-num '+sClass+'">'+p.stock+'</div><div><div class="part-stock-label">adet</div><div class="part-stock-status '+ssClass+'">'+ssText+'</div></div></div>';
    html += '<div class="part-stock-controls">';
    html += '<button class="part-stock-btn" title="Stok -" onclick="adjustStock(\''+p.id+'\',-1)"'+(p.stock<=0?' disabled':'')+'><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/></svg></button>';
    html += '<button class="part-stock-btn" title="Stok +" onclick="adjustStock(\''+p.id+'\',1)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></button>';
    html += '<button class="part-stock-btn part-btn-delete" title="Sil" onclick="deletePart(\''+p.id+'\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>';
    html += '</div></div></div>';
  }
  grid.innerHTML = html;
}

`;

fs.writeFileSync(fpath, before + newCode + after, 'utf8');
console.log('Done! File rewritten successfully.');
