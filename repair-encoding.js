const fs = require('fs');
const path = require('path');

const files = [
  'index.html',
  'collector.html',
  'collector.js',
  'collector.css',
  path.join('assets', 'index-BxoXtaFZ.js'),
];

function score(text) {
  const matches = text.match(/[ÃÅÄÂâ�]/g);
  return matches ? matches.length : 0;
}

function repairText(text) {
  let current = text;

  for (let i = 0; i < 3; i += 1) {
    const repaired = Buffer.from(current, 'latin1').toString('utf8');
    if (score(repaired) >= score(current)) {
      break;
    }
    current = repaired;
  }

  return current
    .replace(/�️/g, '⚠️')
    .replace(/ï¿½ï¸/g, '⚠️')
    .replace(/ğŸŽ/g, '🎁')
    .replace(/ğŸŽ‰/g, '🎉')
    .replace(/ğŸšš/g, '🚚')
    .replace(/ğŸš«/g, '🚫')
    .replace(/ğŸ”’/g, '🔒');
}

for (const file of files) {
  const fullPath = path.join(__dirname, file);
  if (!fs.existsSync(fullPath)) {
    continue;
  }

  const original = fs.readFileSync(fullPath, 'utf8');
  const repaired = repairText(original);

  if (repaired !== original) {
    fs.writeFileSync(fullPath, repaired, 'utf8');
    console.log(`repaired ${file}`);
  } else {
    console.log(`unchanged ${file}`);
  }
}
