const fs = require('fs');
const file = 'c:/xampp/htdocs/index.html';
let data = fs.readFileSync(file, 'utf8');

const toAdd = `
\t<link rel="manifest" href="/manifest.json">
\t<link rel="icon" type="image/svg+xml" href="/Boomer.svg">
`;

if (!data.includes('rel="manifest"')) {
  // Try finding </title> or <meta name="theme-color">
  const target = '<meta name="theme-color" content="#ffcc00">';
  if (data.includes(target)) {
    data = data.replace(target, target + toAdd);
    fs.writeFileSync(file, data, 'utf8');
    console.log('Manifest and SVG links added successfully to index.html');
  } else {
    console.log('Target string not found, cannot inject.');
  }
} else {
  console.log('Manifest is already in index.html');
}
