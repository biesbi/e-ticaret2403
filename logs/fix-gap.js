const fs = require('fs');
const file = 'c:/xampp/htdocs/index.html';
let data = fs.readFileSync(file, 'utf8');

const targetStr = `\t\tbody {
\t\t\t\tpadding-top: var(--mobile-sticky-offset) !important;
\t\t\t}

\t\t\t.app-header.mobile-only,
\t\t\t.modern-header.mobile-only,
\t\t\theader.mobile-only {
\t\t\t\tposition: fixed !important;
\t\t\t\ttop: var(--mobile-banner-height) !important;
\t\t\t\tleft: 0 !important;
\t\t\t\tright: 0 !important;
\t\t\t\tz-index: 14010 !important;
\t\t\t}`;

const replacementStr = `\t\tbody {
\t\t\t\tpadding-top: var(--mobile-banner-height) !important;
\t\t\t}

\t\t\t.app-header.mobile-only,
\t\t\t.modern-header.mobile-only,
\t\t\theader.mobile-only {
\t\t\t\tposition: fixed !important;
\t\t\t\ttop: var(--mobile-banner-height) !important;
\t\t\t\tleft: 0 !important;
\t\t\t\tright: 0 !important;
\t\t\t\tz-index: 14010 !important;
\t\t\t\tbackground: rgba(255, 255, 255, 0.5) !important;
\t\t\t\tbackdrop-filter: blur(16px) !important;
\t\t\t\t-webkit-backdrop-filter: blur(16px) !important;
\t\t\t\tborder-bottom: 1px solid rgba(255, 255, 255, 0.4) !important;
\t\t\t\tbox-shadow: 0 4px 24px rgba(0, 0, 0, 0.06) !important;
\t\t\t}`;

if (data.includes(targetStr)) {
  data = data.replace(targetStr, replacementStr);
  fs.writeFileSync(file, data, 'utf8');
  console.log('Successfully replaced mobile header layout CSS in index.html to remove the white gap.');
} else {
  // Let me try replacing less strictly by matching line by line
  console.log('Strict replacement failed. Trying partial replacement.');
  
  if (data.includes('padding-top: var(--mobile-sticky-offset) !important;')) {
      data = data.replace('padding-top: var(--mobile-sticky-offset) !important;', 'padding-top: var(--mobile-banner-height) !important;');
      console.log('Replaced padding-top offset on body.');
  }
  
  const headerTarget = 'z-index: 14010 !important;';
  if (data.includes(headerTarget)) {
      const headerReplacement = headerTarget + `\n\t\t\t\tbackground: rgba(255, 255, 255, 0.5) !important;\n\t\t\t\tbackdrop-filter: blur(16px) !important;\n\t\t\t\t-webkit-backdrop-filter: blur(16px) !important;\n\t\t\t\tborder-bottom: 1px solid rgba(255, 255, 255, 0.4) !important;\n\t\t\t\tbox-shadow: 0 8px 32px rgba(31, 38, 135, 0.05) !important;`;
      data = data.replace(headerTarget, headerReplacement);
      console.log('Added glassmorphism styles to mobile header to blend with slider.');
  }
  
  fs.writeFileSync(file, data, 'utf8');
}
