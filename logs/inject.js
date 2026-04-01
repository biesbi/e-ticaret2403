const fs = require('fs');
const file = 'c:/xampp/htdocs/index.html';
let data = fs.readFileSync(file, 'utf8');
if (!data.includes('admin-overrides.css')) {
  data = data.replace('</head>', '  <link rel="stylesheet" href="/admin-overrides.css">\n</head>');
  fs.writeFileSync(file, data, 'utf8');
  console.log('Added admin-overrides.css to index.html');
} else {
  console.log('Already there');
}
