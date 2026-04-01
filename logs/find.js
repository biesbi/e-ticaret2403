const fs = require('fs');
const path = require('path');
const p = 'c:/xampp/htdocs/assets';
const files = fs.readdirSync(p).filter(f => f.startsWith('index') && f.endsWith('.js'));
for (const f of files) {
  const data = fs.readFileSync(path.join(p, f), 'utf8');
  const idx = data.indexOf('Toplam Sat');
  if (idx !== -1) {
    console.log(`Found in ${f}`);
    console.log(data.substring(Math.max(0, idx - 50), idx + 2000));
    break;
  }
}
