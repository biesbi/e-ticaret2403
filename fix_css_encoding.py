import os, re

assets_dir = '/home/user/e-ticaret2403/assets'
converted = 0

def escape_nonascii_in_css(content_bytes):
    try:
        text = content_bytes.decode('utf-8')
    except UnicodeDecodeError:
        return content_bytes, 0

    count = [0]
    def replace_char(m):
        ch = m.group(0)
        code = ord(ch)
        if code > 127:
            count[0] += 1
            # CSS unicode escape: \XXXXXX followed by space
            return '\\{:06x} '.format(code)
        return ch

    result = re.sub(r'[^\x00-\x7F]', replace_char, text)
    return result.encode('ascii'), count[0]

for fn in sorted(os.listdir(assets_dir)):
    if fn.endswith('.css'):
        path = os.path.join(assets_dir, fn)
        with open(path, 'rb') as f:
            orig = f.read()
        # Check if has non-ASCII
        if any(b > 127 for b in orig):
            new_content, cnt = escape_nonascii_in_css(orig)
            if cnt > 0:
                with open(path, 'wb') as f:
                    f.write(new_content)
                print('  {}: {} chars escaped'.format(fn, cnt))
                converted += 1

print('\nTotal CSS files converted: {}'.format(converted))
