/* ═══════════════════════════════════════════════════════════
   BoomerItems — Collector Platform JS
   ═══════════════════════════════════════════════════════════ */

// ─── STORAGE HELPERS ─────────────────────────────────────────
const DB_USERS       = 'bi_users';
const DB_SESSION     = 'bi_session';
const DB_COLLECTION  = 'bi_collection';
const DB_PARTS       = 'bi_parts';

const getDB  = k => JSON.parse(localStorage.getItem(k) || '{}');
const setDB  = (k, v) => localStorage.setItem(k, JSON.stringify(v));
const getArr = k => JSON.parse(localStorage.getItem(k) || '[]');
const setArr = (k, v) => localStorage.setItem(k, JSON.stringify(v));

// ─── LEVEL SYSTEM ────────────────────────────────────────────
const LEVELS = [
  { level: 1, title: 'Çırak Koleksiyoner',   xpNeeded: 0,    emoji: '🎯' },
  { level: 2, title: 'Meraklı',              xpNeeded: 100,  emoji: '🔍' },
  { level: 3, title: 'Koleksiyoner',         xpNeeded: 250,  emoji: '🎮' },
  { level: 4, title: 'Uzman Koleksiyoner',   xpNeeded: 500,  emoji: '⭐' },
  { level: 5, title: 'Seçkin Koleksiyoner',  xpNeeded: 1000, emoji: '🏆' },
  { level: 6, title: 'Efsane Koleksiyoner',  xpNeeded: 2000, emoji: '👑' },
  { level: 7, title: 'Koleksiyoner Ustası',  xpNeeded: 4000, emoji: '💎' },
];

const ACHIEVEMENTS = [
  { id: 'first_item',   icon: '🎁', name: 'İlk Adım',      desc: 'İlk ürününü ekle',      xp: 50,  check: c => c.length >= 1 },
  { id: 'five_items',   icon: '📦', name: 'Beşli Koleksiyon', desc: '5 ürün ekle',         xp: 100, check: c => c.length >= 5 },
  { id: 'ten_items',    icon: '🎯', name: 'İki Haneli',     desc: '10 ürün ekle',          xp: 200, check: c => c.length >= 10 },
  { id: 'lego_fan',     icon: '🧱', name: 'LEGO Hayranı',  desc: '3+ LEGO parça',          xp: 75,  check: c => c.filter(i=>i.category==='lego').length >= 3 },
  { id: 'diecast_fan',  icon: '🚗', name: 'Diecast Tutkunu', desc: '3+ Diecast',           xp: 75,  check: c => c.filter(i=>i.category==='diecast').length >= 3 },
  { id: 'funko_fan',    icon: '🎭', name: 'Funko Kolektörü', desc: '3+ Funko Pop',         xp: 75,  check: c => c.filter(i=>i.category==='funko').length >= 3 },
  { id: 'big_spender',  icon: '💰', name: 'Büyük Yatırım', desc: '₺10,000 değer geç',     xp: 300, check: c => c.reduce((s,i)=>s+(+i.currentPrice||0),0)>=10000 },
  { id: 'mint_lover',   icon: '✨', name: 'Mint Sevdalısı', desc: '5 Mint ürün',           xp: 150, check: c => c.filter(i=>i.condition==='mint').length >= 5 },
  { id: 'star_wars',    icon: '⚔️', name: 'Jedi Kolektörü', desc: '3+ Star Wars',         xp: 100, check: c => c.filter(i=>i.category==='star_wars').length >= 3 },
  { id: 'all_cats',     icon: '🌟', name: 'Çok Yönlü',     desc: 'Her kategoriden 1 ürün', xp: 200, check: c => {
    const cats = new Set(c.map(i=>i.category)); return ['lego','diecast','funko','star_wars'].every(cat=>cats.has(cat));
  }},
];

const CAT_EMOJI = { lego:'🧱', diecast:'🚗', funko:'🎭', star_wars:'⚔️', other:'📦' };
const CAT_LABEL = { lego:'LEGO', diecast:'DIECAST', funko:'FUNKO POP', star_wars:'STAR WARS', other:'DİĞER' };
const COND_LABEL = { mint:'Mint', excellent:'Mükemmel', good:'İyi', fair:'Orta' };
const PART_CAT_LABEL = {
  lego_technic:'LEGO Technic', lego_city:'LEGO City', lego_star_wars:'LEGO Star Wars',
  diecast_wheels:'Diecast Tekerlek', diecast_body:'Diecast Gövde', funko_accessories:'Funko Aksesuar'
};
const PART_COND_LABEL = { new:'Sıfır', used_good:'Kullanılmış-İyi', used_fair:'Kullanılmış-Orta', missing:'Eksik/Arıyorum' };

// ─── STATE ───────────────────────────────────────────────────
let currentUser = null;

// ─── INIT ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  seedDemoData();
  const session = localStorage.getItem(DB_SESSION);
  if (session) {
    const users = getDB(DB_USERS);
    if (users[session]) { currentUser = users[session]; showApp(); return; }
  }
  showAuth();
  renderParticles();
});

function seedDemoData() {
  const users = getDB(DB_USERS);
  if (users['demo']) return;
  // Demo user
  users['demo'] = {
    username: 'demo', displayName: 'Demo Koleksiyoner',
    email: 'demo@boomeritems.com', password: 'demo123',
    bio: 'BoomerItems demo hesabı', favCat: 'lego',
    xp: 750, joinDate: new Date().toISOString(),
    unlockedAchievements: ['first_item','five_items','lego_fan'],
  };
  setDB(DB_USERS, users);

  const demo_coll_key = 'bi_collection_demo';
  if (!localStorage.getItem(demo_coll_key)) {
    const items = [
      { id: uid(), name: 'LEGO Star Wars Millennium Falcon 75376', category: 'lego', condition: 'mint', buyPrice: 1750, currentPrice: 1909, setNo: '75376', notes: 'Özel koleksiyon', wishlist: false, addedAt: Date.now() - 86400000*5 },
      { id: uid(), name: 'LEGO Ninjago Ejderinsan 71841', category: 'lego', condition: 'excellent', buyPrice: 2000, currentPrice: 2250, setNo: '71841', notes: '', wishlist: false, addedAt: Date.now() - 86400000*4 },
      { id: uid(), name: 'Hot Wheels Lamborghini Huracán', category: 'diecast', condition: 'mint', buyPrice: 85, currentPrice: 85, setNo: '', notes: '', wishlist: false, addedAt: Date.now() - 86400000*3 },
      { id: uid(), name: 'Funko Pop! Batman War Zone #500', category: 'funko', condition: 'mint', buyPrice: 1200, currentPrice: 1700, setNo: '500', notes: 'Sınırlı seri', wishlist: false, addedAt: Date.now() - 86400000*2 },
      { id: uid(), name: 'LEGO Star Wars Klon Savaşları 75378', category: 'star_wars', condition: 'good', buyPrice: 1100, currentPrice: 1220, setNo: '75378', notes: '', wishlist: false, addedAt: Date.now() - 86400000 },
      { id: uid(), name: 'Mini GT Ferrari 488 GT3', category: 'diecast', condition: 'excellent', buyPrice: 450, currentPrice: 520, setNo: '', notes: '', wishlist: false, addedAt: Date.now() - 3600000 },
      { id: uid(), name: 'LEGO Technic Bugatti Chiron 42083', category: 'lego', condition: 'mint', buyPrice: 4500, currentPrice: 6000, setNo: '42083', notes: 'Kutusunda', wishlist: true, addedAt: Date.now() - 7200000 },
    ];
    setArr(demo_coll_key, items);
  }

  const demo_parts_key = 'bi_parts_demo';
  if (!localStorage.getItem(demo_parts_key)) {
    const parts = [
      { id: uid(), partNo: '3001', name: '2x4 Tuğla', category: 'lego_city', color: 'Kırmızı', stock: 12, condition: 'new' },
      { id: uid(), partNo: '3003', name: '2x2 Tuğla', category: 'lego_city', color: 'Mavi', stock: 8, condition: 'new' },
      { id: uid(), partNo: '3004', name: '1x2 Tuğla', category: 'lego_star_wars', color: 'Gri', stock: 25, condition: 'new' },
      { id: uid(), partNo: '6541', name: 'Technic Pin', category: 'lego_technic', color: 'Siyah', stock: 3, condition: 'used_good' },
      { id: uid(), partNo: '2450', name: 'Uzay Kalkanı', category: 'lego_star_wars', color: 'Beyaz', stock: 0, condition: 'missing' },
      { id: uid(), partNo: 'HW-W12', name: '1:64 Plastik Lastik', category: 'diecast_wheels', color: 'Siyah', stock: 4, condition: 'new' },
      { id: uid(), partNo: 'FP-ACC-01', name: 'Thor Çekici', category: 'funko_accessories', color: 'Gri', stock: 1, condition: 'used_good' },
    ];
    setArr(demo_parts_key, parts);
  }
}

function uid() { return Date.now().toString(36) + Math.random().toString(36).slice(2); }

// ─── AUTH ─────────────────────────────────────────────────────
function switchTab(tab) {
  document.getElementById('loginForm').style.display    = tab==='login'    ? '' : 'none';
  document.getElementById('registerForm').style.display = tab==='register' ? '' : 'none';
  document.getElementById('tabLogin').classList.toggle('active', tab==='login');
  document.getElementById('tabRegister').classList.toggle('active', tab==='register');
}

function handleLogin() {
  const uname = document.getElementById('loginUsername').value.trim();
  const pass  = document.getElementById('loginPassword').value;
  const errEl = document.getElementById('loginError');
  errEl.classList.remove('show');

  if (!uname || !pass) { showErr(errEl, 'Lütfen tüm alanları doldurun.'); return; }
  const users = getDB(DB_USERS);
  const user  = users[uname];
  if (!user || user.password !== pass) { showErr(errEl, 'Kullanıcı adı veya şifre hatalı.'); return; }

  currentUser = user;
  localStorage.setItem(DB_SESSION, uname);
  showApp();
}

function loginDemo() {
  document.getElementById('loginUsername').value = 'demo';
  document.getElementById('loginPassword').value = 'demo123';
  handleLogin();
}

function handleRegister() {
  const uname = document.getElementById('regUsername').value.trim().toLowerCase();
  const dname = document.getElementById('regDisplayName').value.trim();
  const email = document.getElementById('regEmail').value.trim();
  const pass  = document.getElementById('regPassword').value;
  const errEl = document.getElementById('registerError');
  errEl.classList.remove('show');

  if (!uname || !dname || !email || !pass) { showErr(errEl, 'Tüm alanları doldurun.'); return; }
  if (pass.length < 6) { showErr(errEl, 'Şifre en az 6 karakter olmalı.'); return; }
  if (!/^[a-z0-9_]+$/.test(uname)) { showErr(errEl, 'Kullanıcı adı yalnızca harf, rakam ve _ içerebilir.'); return; }

  const users = getDB(DB_USERS);
  if (users[uname]) { showErr(errEl, 'Bu kullanıcı adı zaten alınmış.'); return; }

  users[uname] = { username: uname, displayName: dname, email, password: pass, bio: '', favCat: '', xp: 0, joinDate: new Date().toISOString(), unlockedAchievements: [] };
  setDB(DB_USERS, users);
  currentUser = users[uname];
  localStorage.setItem(DB_SESSION, uname);
  showApp();
  toast('Hesabın oluşturuldu! Hoş geldin 🎉', 'success');
}

function showErr(el, msg) { el.textContent = msg; el.classList.add('show'); }

function logout() {
  localStorage.removeItem(DB_SESSION);
  currentUser = null;
  document.getElementById('mainApp').style.display = 'none';
  document.getElementById('authOverlay').style.display = 'flex';
  document.getElementById('loginUsername').value = '';
  document.getElementById('loginPassword').value = '';
}

// ─── APP ──────────────────────────────────────────────────────
function showApp() {
  document.getElementById('authOverlay').style.display = 'none';
  document.getElementById('mainApp').style.display = 'flex';
  navigate('dashboard');
  updateSidebar();
  updateXP();
}

function navigate(section) {
  document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  const sect = document.getElementById('sect-' + section);
  if (sect) sect.style.display = '';
  const navEl = document.querySelector(`.nav-item[data-section="${section}"]`);
  if (navEl) navEl.classList.add('active');

  const titles = { dashboard: 'Dashboard', collection: 'Koleksiyonum', parts: 'Yedek Parçalar', stockcheck: 'Stok Kontrol', collectors: 'Koleksiyonerler', profile: 'Profilim' };
  document.getElementById('pageTitle').textContent = titles[section] || section;

  if (section === 'dashboard')   renderDashboard();
  if (section === 'collection')  renderCollection();
  if (section === 'parts')       renderParts();
  if (section === 'collectors')  renderCollectors();
  if (section === 'profile')     renderProfile();
}

function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const main = document.getElementById('mainContent');
  sidebar.classList.toggle('collapsed');
  main.classList.toggle('sidebar-collapsed');
}

// ─── USER DATA HELPERS ────────────────────────────────────────
function collKey()  { return 'bi_collection_' + currentUser.username; }
function partsKey() { return 'bi_parts_'      + currentUser.username; }
function getCollection()  { return getArr(collKey()); }
function setCollection(v) { setArr(collKey(), v); }
function getParts()       { return getArr(partsKey()); }
function setParts(v)      { setArr(partsKey(), v); }

function saveUser() {
  const users = getDB(DB_USERS);
  users[currentUser.username] = currentUser;
  setDB(DB_USERS, users);
}

// ─── XP / LEVEL ──────────────────────────────────────────────
function getLevelInfo(xp) {
  let lvl = LEVELS[0];
  for (const l of LEVELS) { if (xp >= l.xpNeeded) lvl = l; else break; }
  const nextIdx = LEVELS.indexOf(lvl) + 1;
  const next = LEVELS[nextIdx];
  const xpInLevel = xp - lvl.xpNeeded;
  const xpNeeded  = next ? next.xpNeeded - lvl.xpNeeded : Infinity;
  return { ...lvl, next, xpInLevel, xpNeeded };
}

function addXP(amount) {
  currentUser.xp = (currentUser.xp || 0) + amount;
  saveUser();
  updateXP();
}

function updateXP() {
  const info = getLevelInfo(currentUser.xp || 0);
  document.getElementById('xpDisplay').textContent = currentUser.xp || 0;
  document.getElementById('xpNextDisplay').textContent = info.next ? info.next.xpNeeded : '∞';
  const pct = info.xpNeeded === Infinity ? 100 : Math.min(100, (info.xpInLevel / info.xpNeeded) * 100);
  document.getElementById('xpFill').style.width = pct + '%';
  document.getElementById('statLevel').textContent = info.level;
  document.getElementById('sidebarLevel').textContent = `Seviye ${info.level} — ${info.title}`;
}

function updateSidebar() {
  const init = (currentUser.displayName || currentUser.username)[0].toUpperCase();
  document.getElementById('sidebarAvatar').textContent = init;
  document.getElementById('sidebarName').textContent   = currentUser.displayName || currentUser.username;
  const coll = getCollection();
  document.getElementById('navCollBadge').textContent  = coll.length;
}

// ─── ACHIEVEMENTS CHECK ───────────────────────────────────────
function checkAchievements() {
  const coll = getCollection();
  const unlocked = currentUser.unlockedAchievements || [];
  let newUnlocks = [];

  for (const a of ACHIEVEMENTS) {
    if (!unlocked.includes(a.id) && a.check(coll)) {
      unlocked.push(a.id);
      newUnlocks.push(a);
      addXP(a.xp);
    }
  }
  currentUser.unlockedAchievements = unlocked;
  saveUser();
  newUnlocks.forEach(a => toast(`${a.icon} Başarı Açıldı: ${a.name} (+${a.xp} XP)`, 'success'));
  return unlocked;
}

// ─── DASHBOARD ────────────────────────────────────────────────
function renderDashboard() {
  const coll = getCollection();
  const info = getLevelInfo(currentUser.xp || 0);

  document.getElementById('welcomeGreeting').textContent = `Merhaba, ${currentUser.displayName || currentUser.username}! ${info.emoji}`;
  document.getElementById('statTotal').textContent = coll.filter(i=>!i.wishlist).length;
  document.getElementById('statSets').textContent  = [...new Set(coll.filter(i=>i.setNo && !i.wishlist).map(i=>i.setNo))].length;
  const totalVal = coll.filter(i=>!i.wishlist).reduce((s,i)=>s+(+i.currentPrice||0),0);
  document.getElementById('statValue').textContent  = '₺' + totalVal.toLocaleString('tr-TR');
  document.getElementById('statLevel').textContent  = info.level;
  updateXP();

  // Recent items
  const recentEl = document.getElementById('recentItems');
  const recent = [...coll].sort((a,b)=>b.addedAt-a.addedAt).slice(0,5);
  if (recent.length === 0) { recentEl.innerHTML = '<div class="empty-state-sm">Koleksiyonun boş. Hadi başlayalım! 🚀</div>'; }
  else recentEl.innerHTML = recent.map(item => `
    <div class="recent-item" onclick="navigate('collection')">
      <span class="recent-cat-badge cat-${item.category}">${CAT_EMOJI[item.category]}</span>
      <span class="recent-name">${item.name}</span>
      <span class="recent-price">₺${(+item.currentPrice||0).toLocaleString('tr-TR')}</span>
      ${item.wishlist ? '<span style="font-size:0.7rem;color:#a855f7">İstek</span>' : ''}
    </div>`).join('');

  // Badges in welcome
  const badges = (currentUser.unlockedAchievements || []).slice(-3);
  document.getElementById('welcomeBadges').innerHTML = badges.map(id => {
    const a = ACHIEVEMENTS.find(x=>x.id===id);
    return a ? `<span style="font-size:1.6rem" title="${a.name}">${a.icon}</span>` : '';
  }).join('');

  // Achievements
  const unlocked = currentUser.unlockedAchievements || [];
  document.getElementById('achievementsGrid').innerHTML = ACHIEVEMENTS.map(a => `
    <div class="achievement-card ${unlocked.includes(a.id) ? 'unlocked' : 'locked'}" title="${a.desc}">
      <div class="achievement-icon">${a.icon}</div>
      <div class="achievement-name">${a.name}</div>
      <div class="achievement-desc">${a.desc}</div>
      ${unlocked.includes(a.id) ? `<div style="color:var(--accent);font-size:0.7rem;margin-top:4px">+${a.xp} XP ✓</div>` : ''}
    </div>`).join('');
}

// ─── COLLECTION ───────────────────────────────────────────────
function renderCollection() {
  const coll     = getCollection();
  const search   = (document.getElementById('collSearch')?.value || '').toLowerCase();
  const filter   = document.getElementById('collFilter')?.value || 'all';
  const sort     = document.getElementById('collSort')?.value   || 'newest';
  const navBadge = document.getElementById('navCollBadge');
  if (navBadge) navBadge.textContent = coll.length;

  let items = coll.filter(i => {
    const matchSearch = !search || i.name.toLowerCase().includes(search) || (i.setNo||'').toLowerCase().includes(search);
    const matchFilter = filter === 'all' || i.category === filter;
    return matchSearch && matchFilter;
  });

  if (sort === 'newest')     items.sort((a,b) => b.addedAt - a.addedAt);
  if (sort === 'oldest')     items.sort((a,b) => a.addedAt - b.addedAt);
  if (sort === 'name')       items.sort((a,b) => a.name.localeCompare(b.name, 'tr'));
  if (sort === 'value_high') items.sort((a,b) => (+b.currentPrice||0) - (+a.currentPrice||0));
  if (sort === 'value_low')  items.sort((a,b) => (+a.currentPrice||0) - (+b.currentPrice||0));

  const grid = document.getElementById('collectionGrid');
  if (!grid) return;

  if (items.length === 0) {
    grid.innerHTML = `<div class="empty-collection">
      <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      <h3>${search||filter!=='all' ? 'Sonuç bulunamadı' : 'Koleksiyonun boş'}</h3>
      <p>${search||filter!=='all' ? 'Farklı arama deneyin' : 'İlk ürününü eklemek için + Yeni Ekle butonuna tıkla'}</p>
    </div>`;
    return;
  }

  grid.innerHTML = items.map(item => {
    const gain    = (+item.currentPrice||0) - (+item.buyPrice||0);
    const gainPct = item.buyPrice > 0 ? Math.round((gain/item.buyPrice)*100) : 0;
    const gainClass = gain >= 0 ? 'gain-up' : 'gain-down';
    const gainSign  = gain >= 0 ? '+' : '';
    return `
    <div class="item-card ${item.wishlist ? 'wishlist-item' : ''}" data-id="${item.id}">
      <div class="item-card-thumb">
        <span>${CAT_EMOJI[item.category] || '📦'}</span>
        ${item.wishlist ? '<div class="item-wishlist-ribbon">İstek Listesi</div>' : ''}
        <div class="item-condition-badge cond-${item.condition}">${COND_LABEL[item.condition]}</div>
      </div>
      <div class="item-card-body">
        <span class="item-cat-badge cat-${item.category}">${CAT_LABEL[item.category]}</span>
        <div class="item-name">${item.name}</div>
        ${item.setNo ? `<div class="item-setno">No: ${item.setNo}</div>` : ''}
        <div class="item-price-row">
          <div class="item-price">₺${(+item.currentPrice||0).toLocaleString('tr-TR')}</div>
          ${gain !== 0 ? `<div class="item-gain ${gainClass}">${gainSign}${gainPct}%</div>` : ''}
        </div>
      </div>
      <div class="item-card-actions">
        <button class="btn-icon" title="Düzenle" onclick="editItem('${item.id}')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <button class="btn-danger" title="Sil" onclick="deleteItem('${item.id}')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
        </button>
        <div style="margin-left:auto;display:flex;align-items:center;gap:0.25rem;font-size:0.7rem;color:var(--text-muted)">
          ${new Date(item.addedAt).toLocaleDateString('tr-TR')}
        </div>
      </div>
    </div>`;
  }).join('');
}

function openAddModal() {
  document.getElementById('editItemId').value = '';
  document.getElementById('itemName').value = '';
  document.getElementById('itemCategory').value = 'lego';
  document.getElementById('itemCondition').value = 'mint';
  document.getElementById('itemBuyPrice').value = '';
  document.getElementById('itemCurrentPrice').value = '';
  document.getElementById('itemSetNo').value = '';
  document.getElementById('itemNotes').value = '';
  document.getElementById('itemWishlist').checked = false;
  document.getElementById('modalTitle').textContent = 'Koleksiyona Ekle';
  document.getElementById('addModal').style.display = 'flex';
}

function editItem(id) {
  const coll = getCollection();
  const item = coll.find(i => i.id === id);
  if (!item) return;
  document.getElementById('editItemId').value = id;
  document.getElementById('itemName').value = item.name;
  document.getElementById('itemCategory').value = item.category;
  document.getElementById('itemCondition').value = item.condition;
  document.getElementById('itemBuyPrice').value = item.buyPrice || '';
  document.getElementById('itemCurrentPrice').value = item.currentPrice || '';
  document.getElementById('itemSetNo').value = item.setNo || '';
  document.getElementById('itemNotes').value = item.notes || '';
  document.getElementById('itemWishlist').checked = item.wishlist || false;
  document.getElementById('modalTitle').textContent = 'Ürünü Düzenle';
  document.getElementById('addModal').style.display = 'flex';
}

function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }

function saveItem() {
  const name = document.getElementById('itemName').value.trim();
  if (!name) { toast('Ürün adı gerekli!', 'error'); return; }

  const coll   = getCollection();
  const editId = document.getElementById('editItemId').value;
  const item   = {
    id: editId || uid(),
    name,
    category:     document.getElementById('itemCategory').value,
    condition:    document.getElementById('itemCondition').value,
    buyPrice:     +document.getElementById('itemBuyPrice').value || 0,
    currentPrice: +document.getElementById('itemCurrentPrice').value || 0,
    setNo:        document.getElementById('itemSetNo').value.trim(),
    notes:        document.getElementById('itemNotes').value.trim(),
    wishlist:     document.getElementById('itemWishlist').checked,
    addedAt:      editId ? (coll.find(i=>i.id===editId)?.addedAt || Date.now()) : Date.now(),
  };

  if (editId) {
    const idx = coll.findIndex(i => i.id === editId);
    if (idx >= 0) coll[idx] = item;
  } else {
    coll.unshift(item);
    if (!item.wishlist) addXP(20);
  }
  setCollection(coll);
  checkAchievements();
  updateSidebar();
  closeAddModal();
  renderCollection();
  renderDashboard();
  toast(editId ? 'Ürün güncellendi ✓' : 'Koleksiyona eklendi! 🎉', 'success');
}

function deleteItem(id) {
  if (!confirm('Bu ürünü silmek istiyor musun?')) return;
  const coll = getCollection().filter(i => i.id !== id);
  setCollection(coll);
  updateSidebar();
  renderCollection();
  renderDashboard();
  toast('Ürün silindi.', 'info');
}

// Quick add from dashboard
function quickAdd() {
  const name = document.getElementById('qaName').value.trim();
  if (!name) { toast('Ürün adı girin!', 'error'); return; }
  const item = {
    id: uid(), name,
    category:     document.getElementById('qaCategory').value,
    condition:    document.getElementById('qaCondition').value,
    buyPrice:     +document.getElementById('qaPrice').value || 0,
    currentPrice: +document.getElementById('qaPrice').value || 0,
    setNo: '', notes: '', wishlist: false, addedAt: Date.now(),
  };
  const coll = getCollection();
  coll.unshift(item);
  setCollection(coll);
  checkAchievements();
  updateSidebar();
  document.getElementById('qaName').value = '';
  document.getElementById('qaPrice').value = '';
  renderDashboard();
  toast('Koleksiyona eklendi! 🎉', 'success');
}

// ─── PARTS ────────────────────────────────────────────────────
function renderParts() {
  const parts  = getParts();
  const search = (document.getElementById('partsSearch')?.value || '').toLowerCase();
  const filter = document.getElementById('partsFilter')?.value || 'all';
  const tbody  = document.getElementById('partsTableBody');
  if (!tbody) return;

  let items = parts.filter(p => {
    const matchSearch = !search || p.name.toLowerCase().includes(search) || p.partNo.toLowerCase().includes(search);
    const matchFilter = filter === 'all' || p.category === filter;
    return matchSearch && matchFilter;
  });

  if (items.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem">
      ${search||filter!=='all' ? 'Sonuç bulunamadı.' : 'Henüz parça yok. Eklemek için + Parça Ekle butonunu kullan.'}
    </td></tr>`;
    return;
  }

  tbody.innerHTML = items.map(p => {
    const stockClass = p.stock > 5 ? 'stock-in' : p.stock > 0 ? 'stock-low' : 'stock-out';
    const stockLabel = p.stock > 5 ? '✔ Stokta' : p.stock > 0 ? `⚠ Az (${p.stock})` : '✘ Yok';
    return `<tr>
      <td style="font-family:monospace;color:var(--accent)">${p.partNo}</td>
      <td style="font-weight:600">${p.name}</td>
      <td>${PART_CAT_LABEL[p.category]||p.category}</td>
      <td>${p.color||'—'}</td>
      <td><b>${p.stock}</b> adet</td>
      <td><span class="stock-badge ${stockClass}">${stockLabel}</span></td>
      <td>
        <div style="display:flex;gap:0.4rem">
          <button class="btn-icon" title="Stok +" onclick="adjustStock('${p.id}', 1)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          </button>
          <button class="btn-icon" title="Stok -" onclick="adjustStock('${p.id}', -1)" ${p.stock<=0?'disabled':''}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/></svg>
          </button>
          <button class="btn-danger" title="Sil" onclick="deletePart('${p.id}')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          </button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function adjustStock(id, delta) {
  const parts = getParts();
  const idx   = parts.findIndex(p => p.id === id);
  if (idx < 0) return;
  parts[idx].stock = Math.max(0, parts[idx].stock + delta);
  setParts(parts);
  renderParts();
}

function deletePart(id) {
  if (!confirm('Bu parçayı silmek istiyor musun?')) return;
  setParts(getParts().filter(p => p.id !== id));
  renderParts();
  toast('Parça silindi.', 'info');
}

function openAddPartModal() { document.getElementById('addPartModal').style.display = 'flex'; }
function closeAddPartModal() { document.getElementById('addPartModal').style.display = 'none'; }

function savePart() {
  const partNo = document.getElementById('partNo').value.trim();
  const name   = document.getElementById('partName').value.trim();
  if (!partNo || !name) { toast('Parça No ve Adı gerekli!', 'error'); return; }

  const part = {
    id: uid(), partNo, name,
    category:  document.getElementById('partCategory').value,
    color:     document.getElementById('partColor').value.trim(),
    stock:     +document.getElementById('partStock').value || 0,
    condition: document.getElementById('partCondition').value,
  };
  const parts = getParts();
  parts.unshift(part);
  setParts(parts);
  closeAddPartModal();
  renderParts();
  toast('Parça eklendi ✓', 'success');
}

// ─── STOCK CHECK ─────────────────────────────────────────────
function handleDrop(e) {
  e.preventDefault();
  e.currentTarget.classList.remove('drag-over');
  const files = e.dataTransfer.files;
  if (files.length > 0) processFile(files[0]);
}

function handleFileInput(e) {
  const file = e.target.files[0];
  if (file) processFile(file);
}

function processFile(file) {
  const reader = new FileReader();
  reader.onload = ev => {
    const text = ev.target.result;
    checkStock(text, file.name);
  };
  reader.readAsText(file, 'utf-8');
}

function checkManualInput() {
  const text = document.getElementById('manualInput').value.trim();
  if (!text) { toast('Lütfen bir liste girin!', 'error'); return; }
  checkStock(text, 'manuel');
}

function checkStock(rawText, source) {
  const lines = rawText.split('\n').map(l => l.trim()).filter(Boolean);
  const queries = [];

  // Detect if CSV with header
  const firstLine = lines[0].toLowerCase();
  const isCSV = firstLine.includes(',') && (firstLine.includes('isim') || firstLine.includes('name') || firstLine.includes('kategori'));
  const startIdx = isCSV ? 1 : 0;

  for (let i = startIdx; i < lines.length; i++) {
    const line = lines[i];
    if (!line) continue;
    if (line.includes(',')) {
      const parts = line.split(',').map(p => p.trim());
      queries.push({ name: parts[0], qty: +parts[2] || 1 });
    } else {
      queries.push({ name: line, qty: 1 });
    }
  }

  // Check against collection AND parts
  const coll  = getCollection();
  const parts = getParts();
  const ALL   = [
    ...coll.map(i  => ({ name: i.name,  type: 'collection', id: i.id,  category: CAT_LABEL[i.category] })),
    ...parts.map(p => ({ name: p.name,  type: 'part',       id: p.id,  category: PART_CAT_LABEL[p.category], stock: p.stock, partNo: p.partNo })),
    ...parts.map(p => ({ name: p.partNo, type: 'part',      id: p.id,  category: PART_CAT_LABEL[p.category], stock: p.stock, partNo: p.partNo })),
  ];

  const results = queries.map(q => {
    const lower = q.name.toLowerCase();
    const match = ALL.find(a => a.name.toLowerCase().includes(lower) || lower.includes(a.name.toLowerCase().split(' ')[0]));
    return { query: q.name, found: !!match, match };
  });

  const found   = results.filter(r => r.found).length;
  const missing = results.length - found;

  const resEl = document.getElementById('stockResults');
  resEl.innerHTML = `
    <div class="stock-summary">
      <div class="stock-sum-card"><div class="stock-sum-val sum-total">${results.length}</div><div class="stock-sum-label">Toplam</div></div>
      <div class="stock-sum-card"><div class="stock-sum-val sum-found">${found}</div><div class="stock-sum-label">Bulundu</div></div>
      <div class="stock-sum-card"><div class="stock-sum-val sum-missing">${missing}</div><div class="stock-sum-label">Yok</div></div>
    </div>
    <div class="stock-result-list">
      ${results.map(r => `
        <div class="stock-result-item ${r.found ? 'found' : 'missing'}">
          <svg class="stock-result-icon ${r.found ? 'found' : 'missing'}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="20" height="20">
            ${r.found ? '<polyline points="20 6 9 17 4 12"/>' : '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'}
          </svg>
          <div class="stock-result-name">${r.query}</div>
          <div class="stock-result-detail">
            ${r.found ? `<span style="color:var(--green)">${r.match.type==='part' ? `Parça — Stok: ${r.match.stock}` : `Koleksiyon — ${r.match.category}`}</span>` : '<span style="color:#f87171">Sistemde yok</span>'}
          </div>
        </div>`).join('')}
    </div>`;
  toast(`${results.length} ürün kontrol edildi — ${found} bulundu, ${missing} yok.`, found===results.length ? 'success' : 'info');
}

// ─── COLLECTORS ───────────────────────────────────────────────
function renderCollectors() {
  const users = getDB(DB_USERS);
  const grid  = document.getElementById('collectorsGrid');
  if (!grid) return;

  const entries = Object.entries(users).sort((a,b) => (b[1].xp||0) - (a[1].xp||0));
  if (entries.length === 0) { grid.innerHTML = '<p style="color:var(--text-muted)">Henüz kayıtlı kullanıcı yok.</p>'; return; }

  grid.innerHTML = entries.map(([uname, u], i) => {
    const info  = getLevelInfo(u.xp || 0);
    const coll  = getArr('bi_collection_' + uname);
    const parts = getArr('bi_parts_'      + uname);
    const total = coll.filter(i=>!i.wishlist).length;
    const totalVal = coll.filter(j=>!j.wishlist).reduce((s,j)=>s+(+j.currentPrice||0),0);
    const isMe = uname === currentUser.username;

    return `<div class="collector-card">
      <div class="collector-card-top">
        <div class="collector-big-avatar">${(u.displayName||uname)[0].toUpperCase()}</div>
        <div>
          <div class="collector-name">${u.displayName||uname}</div>
          <div class="collector-username">@${uname}</div>
          <div class="collector-level">${info.emoji} Seviye ${info.level} — ${info.title}</div>
        </div>
        ${isMe ? '<span class="you-badge">SEN</span>' : ''}
      </div>
      <div class="collector-stats">
        <div class="coll-stat"><div class="coll-stat-val">${total}</div><div class="coll-stat-lbl">Ürün</div></div>
        <div class="coll-stat"><div class="coll-stat-val">${parts.length}</div><div class="coll-stat-lbl">Parça</div></div>
        <div class="coll-stat">
          <div class="coll-stat-val" style="font-size:0.85rem">${totalVal>=10000 ? '₺'+Math.round(totalVal/1000)+'K' : '₺'+totalVal.toLocaleString('tr-TR')}</div>
          <div class="coll-stat-lbl">Değer</div>
        </div>
      </div>
    </div>`;
  }).join('');
}

// ─── PROFILE ─────────────────────────────────────────────────
function renderProfile() {
  const coll   = getCollection();
  const info   = getLevelInfo(currentUser.xp || 0);
  const init   = (currentUser.displayName || currentUser.username)[0].toUpperCase();
  const total  = coll.filter(i=>!i.wishlist).length;
  const sets   = [...new Set(coll.filter(i=>i.setNo&&!i.wishlist).map(i=>i.setNo))].length;
  const val    = coll.filter(i=>!i.wishlist).reduce((s,i)=>s+(+i.currentPrice||0),0);

  document.getElementById('profileAvatar').textContent  = init;
  document.getElementById('profileName').textContent    = currentUser.displayName || currentUser.username;
  document.getElementById('profileUsername').textContent = '@' + currentUser.username;
  document.getElementById('profileLevelBadge').innerHTML = `${info.emoji} Seviye ${info.level} — ${info.title} · ${currentUser.xp||0} XP`;
  document.getElementById('psTotal').textContent  = total;
  document.getElementById('psSets').textContent   = sets;
  document.getElementById('psValue').textContent  = '₺' + val.toLocaleString('tr-TR');

  document.getElementById('editDisplayName').value = currentUser.displayName || '';
  document.getElementById('editFavCat').value      = currentUser.favCat || '';
  document.getElementById('editBio').value         = currentUser.bio || '';
}

function saveProfile() {
  currentUser.displayName = document.getElementById('editDisplayName').value.trim() || currentUser.displayName;
  currentUser.favCat      = document.getElementById('editFavCat').value;
  currentUser.bio         = document.getElementById('editBio').value.trim();
  saveUser();
  updateSidebar();
  renderProfile();
  toast('Profil güncellendi ✓', 'success');
}

// ─── MODAL UTILS ─────────────────────────────────────────────
function closeModal(e) {
  if (e.target.classList.contains('modal-overlay')) {
    document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
  }
}

// ─── TOAST ───────────────────────────────────────────────────
let toastTimer;
function toast(msg, type='info') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className   = 'toast ' + type;
  el.style.display = 'block';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.style.display = 'none'; }, 3500);
}

// ─── PARTICLES ───────────────────────────────────────────────
function renderParticles() {
  const el = document.getElementById('particles');
  if (!el) return;
  const emojis = ['🎮','🧱','🚗','⭐','🏆','🎭','⚔️','💎'];
  for (let i = 0; i < 15; i++) {
    const span = document.createElement('div');
    span.textContent = emojis[Math.floor(Math.random() * emojis.length)];
    Object.assign(span.style, {
      position: 'absolute',
      left: Math.random()*100 + '%',
      top:  Math.random()*100 + '%',
      fontSize: (16 + Math.random()*24) + 'px',
      opacity: (0.04 + Math.random()*0.06).toFixed(2),
      animation: `float ${5+Math.random()*10}s ease-in-out infinite`,
      animationDelay: Math.random()*5 + 's',
      pointerEvents: 'none',
    });
    el.appendChild(span);
  }
  const style = document.createElement('style');
  style.textContent = `@keyframes float { 0%,100%{transform:translateY(0) rotate(0deg)} 50%{transform:translateY(-20px) rotate(5deg)} }`;
  document.head.appendChild(style);
}
