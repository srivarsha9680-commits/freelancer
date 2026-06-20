from pathlib import Path
p = Path('served.html')
text = p.read_text(encoding='utf-8')
old = '![≡ƒ¢í∩╕Å](https://fonts.gstatic.com/s/e/notoemoji/17.0/1f6e1_fe0f/72.png) Scope Creep Defender'
new = '<span class="brand-icon">🛡️</span> Scope Creep Defender'
if old not in text:
    raise SystemExit('pattern not found')
text = text.replace(old, new)
p.write_text(text, encoding='utf-8')
print('fixed')
