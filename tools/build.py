#!/usr/bin/env python3
"""Turns the design files in design/ into the page templates the theme serves (templates/)."""
import re, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
BLOB = {
    '185258e7738f57228e27d59d15ad2727': 'p01', 'ced456605ffcc93b5f67aae1503ec454': 'p06',
    '9e6f42698535a863169b81bb731d04ff': 'g02', '1314b86e9af2c1f6ecf5fc14fe6445d9': 'g03',
    '9dc936d841e9bca8e74b2ad281b88140': 'g04', 'd3a9efe407d9798a514cdd5c4f18f56c': 'g05',
    'fd7bff4c09ae663c49f232d4edddbc0c': 'g07', '83723b08c33cfad30ce6e2b553d0c008': 'g08',
    'c30b026dcfb858775e846238c6e4dc48': 'g11', '084d2e3081ba2f6b3aae7abb162ff4a4': 'g12',
    'd88dfb98c13b8dae5c87dbc1e76981ff': 'g13', '82cfd3e6abbb7e0718eab4ecd009ef4f': 'g14',
    '2a8a36b7bb645bdfe2d1f8223897a863': 'g15', '673c88e1bacff56f8dcb62bffd478f26': 'g16',
    '3834d18e3912dad1467ed4245ab30735': 'g17',
}
# Wording used on the live site where the design still has an open question.
TEXT = {
    'TrangChu': [
        ("'Ở Việt Nam, bạn chuyển khoản ngân hàng. [Cách thanh toán cho người ở nước ngoài]'",
         "'Ở Việt Nam, bạn chuyển khoản ngân hàng. Ở nước ngoài, bạn trả qua PayPal.'"),
        ("['Mình được học trong bao lâu?', '[Thời hạn truy cập khóa học]']", ''),
    ],
    'DangKy': [],
}

def build(name, home_href, signup_href):
    src = (ROOT / 'design' / f'{name}.dc.html').read_text(encoding='utf-8')
    body = src[src.index('<x-dc>'):src.index('</body>')]
    for k, v in BLOB.items():
        body = body.replace('/_blob/' + k, '%%THEME%%/assets/img/' + v + '.jpg')
    assert '/_blob/' not in body, 'unmapped image in ' + name
    body = body.replace("'/_audio/'", "'%%THEME%%/assets/audio/'")
    # An <img src="{{...}}"> would make the browser fetch the placeholder text before the page script fills it in.
    body = body.replace(' src="{{', ' data-dc-src="{{')
    body = body.replace('"/_img/', '"%%THEME%%/assets/img/')
    body = body.replace('href="MuaKhoaHoc.dc.html', 'href="%%SIGNUP%%').replace('href="BanNhac.dc.html"', 'href="%%HOME%%"').replace('href="DangNhap.dc.html"', 'href="%%LOGIN%%"').replace('href="CauHoi.dc.html"', 'href="%%HOME%%cau-hoi-thuong-gap/"').replace('href="SheetNhac.dc.html"', 'href="%%HOME%%sheet-nhac/"')
    for a, b in TEXT[name]:
        assert a in body, (name, a[:40])
        body = body.replace(a, b)
    (ROOT / 'templates' / f'{name}.html').write_text(body, encoding='utf-8')
    left = sorted(set(re.findall(r'\[[A-ZĐÀ-Ỹ][^\]\n{}\'"]{2,40}\]', body)))
    print(name, len(body), 'placeholders left:', left)

build('TrangChu', '/', '/dang-ky/')
build('DangKy', '/', '/dang-ky/')
