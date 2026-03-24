import os

path = r'c:\xampp\htdocs\index.html'
with open(path, 'r', encoding='utf-8') as f:
    lines = f.readlines()

# Head start: 0 to 13 (0-indexed)
header = lines[0:14]

# New style block
new_style = """	<style>
		/* UTILITY: Hide legacy sections */
		.category-blocks-section { display: none !important; }

		/* MOBILE: Bottom Padding Fix for Drawers */
		@media (max-width: 768px) {
			.app-styled-mobile {
				padding-bottom: calc(88px + env(safe-area-inset-bottom)) !important;
			}
			.detail-info-section, .cart-drawer-body {
				padding-bottom: 160px !important;
			}
		}

		/* DESKTOP: Native Header Base Styles (Immediate Fix) */
		@media (min-width: 1024px) {
			body { 
				overflow-y: auto !important;
				background: #fff !important; 
			}
			.mobile-bottom-nav, .app-header.mobile-only {
				display: none !important;
			}
			
			.desktop-storefront-header {
				display: flex !important;
				z-index: 15000 !important;
			}

			.desktop-logo {
				height: 72px !important;
				width: auto !important;
				transform: scale(1.18) !important;
				transform-origin: left center !important;
				filter: drop-shadow(0 4px 12px rgba(0,0,0,0.06)) !important;
			}
		}
	</style>
	<script>window._wca = window._wca || [];</script>
"""

# Rest of file: searching for the line after the script
# We know line 498 was the script.
# In 0-indexed terms, lines[497] is line 498.
# So we take from lines[498:] if it matches the dns-prefetch line.

footer_start_idx = -1
for i, line in enumerate(lines):
    if i > 400 and "<link rel='dns-prefetch' href='http://stats.wp.com/' />" in line:
        footer_start_idx = i
        break

if footer_start_idx != -1:
    footer = lines[footer_start_idx:]
    with open(path, 'w', encoding='utf-8') as f:
        f.writelines(header)
        f.write(new_style)
        f.writelines(footer)
    print("SUCCESS")
else:
    print("ERROR: Footer not found")
