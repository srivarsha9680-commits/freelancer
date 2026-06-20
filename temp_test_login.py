import urllib.request, urllib.parse, re
from http.cookiejar import CookieJar

cj = CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
resp = opener.open('http://localhost:8000/pages/login.php')
html = resp.read().decode('utf-8')
print('GET URL:', resp.geturl())
match = re.search(r'name="csrf_token" value="([^"]+)"', html)
print('token found:', bool(match))
if not match:
    print(html[:800])
    raise SystemExit(1)
token = match.group(1)
print('token:', token)
data = urllib.parse.urlencode({'email':'demo@example.com','password':'password','csrf_token':token}).encode('utf-8')
req = urllib.request.Request('http://localhost:8000/pages/login.php', data=data)
resp2 = opener.open(req)
print('POST URL:', resp2.geturl())
print('POST CODE:', resp2.getcode())
print(resp2.read(1200).decode('utf-8', 'ignore'))
