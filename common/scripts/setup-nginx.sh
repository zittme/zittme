#!/bin/bash
#
# Zittme nginx short-URL (rewrite) one-shot setup
#
# Usage:  sudo bash setup-nginx.sh <domain>
# 예:     sudo bash /var/www/zittme/common/scripts/setup-nginx.sh zittme.zzan.me
#
# 하는 일:
#   1. 동봉된 rewrite 규칙(zittme-nginx.conf)을 /etc/nginx/snippets/zittme.conf 로 복사
#   2. 도메인의 vhost 파일을 찾아 백업 후:
#      - 기존 location / { ... } 블록을 주석 처리 (snippet과 중복 방지)
#      - server 블록 끝에 include snippets/zittme.conf; 삽입
#   3. nginx -t 검사 → 실패하면 자동 롤백, 성공하면 reload
#
# 전제조건: root 권한, nginx, python3 (Ubuntu/Debian 기본 포함)

set -u

DOMAIN="${1:-}"
if [ -z "$DOMAIN" ]; then
    echo "사용법: sudo bash $0 <domain>   (예: zittme.zzan.me)"
    exit 1
fi
if [ "$(id -u)" -ne 0 ]; then
    echo "오류: root 권한이 필요합니다. sudo로 실행하세요."
    exit 1
fi
if ! command -v python3 >/dev/null 2>&1; then
    echo "오류: python3가 필요합니다. (apt install -y python3) 또는 수동 설정을 이용하세요."
    exit 1
fi

# Zittme 설치 경로 = 이 스크립트의 두 단계 상위
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SNIPPET_SRC="$ROOT/common/manual/server_config/zittme-nginx.conf"
SNIPPET_DST="/etc/nginx/snippets/zittme.conf"

if [ ! -f "$SNIPPET_SRC" ]; then
    echo "오류: $SNIPPET_SRC 를 찾을 수 없습니다."
    exit 1
fi

# 1) snippet 복사
mkdir -p /etc/nginx/snippets
cp "$SNIPPET_SRC" "$SNIPPET_DST"
echo "[1/3] snippet 복사 완료: $SNIPPET_DST"

# 2) vhost 찾기 (-R: sites-enabled의 심볼릭 링크를 따라감)
VHOST=""
for f in $(grep -RlsE "server_name[^;]*${DOMAIN}" /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ /etc/nginx/sites-available/ 2>/dev/null); do
    VHOST="$f"
    break
done
if [ -z "$VHOST" ]; then
    echo "오류: server_name ${DOMAIN} 이 포함된 vhost를 찾지 못했습니다."
    echo "      /etc/nginx/sites-available/ 에 사이트 설정을 먼저 만들어 주세요."
    exit 1
fi
# 심볼릭 링크면 실제 파일 수정
VHOST="$(readlink -f "$VHOST")"
BACKUP="${VHOST}.bak.$(date +%Y%m%d%H%M%S)"
cp "$VHOST" "$BACKUP"
echo "[2/3] vhost 수정: $VHOST (백업: $BACKUP)"

python3 - "$VHOST" "$DOMAIN" << 'PYEOF'
import re, sys

path, domain = sys.argv[1], sys.argv[2]
with open(path, encoding='utf-8', errors='replace') as fp:
    lines = fp.readlines()

# server 블록 경계 파싱 (중괄호 깊이 추적)
def parse_server_blocks(lines):
    blocks = []
    i = 0
    n = len(lines)
    while i < n:
        if re.match(r'^\s*server\s*({|\s*$)', re.sub(r'#.*', '', lines[i])):
            # 블록 시작 — 여는 중괄호 찾기
            depth = 0
            j = i
            opened = False
            while j < n:
                code = re.sub(r'#.*', '', lines[j])
                for ch in code:
                    if ch == '{':
                        depth += 1
                        opened = True
                    elif ch == '}':
                        depth -= 1
                if opened and depth == 0:
                    blocks.append((i, j))
                    break
                j += 1
            i = j + 1
        else:
            i += 1
    return blocks

blocks = parse_server_blocks(lines)
target = None
for (s, e) in blocks:
    body = ''.join(lines[s:e+1])
    m = re.search(r'server_name([^;]*);', body)
    if m and re.search(r'\b' + re.escape(domain) + r'\b', m.group(1)):
        # listen 443 또는 리다이렉트가 아닌 본 블록 우선 (return 301만 있는 블록 제외)
        if not re.search(r'return\s+30[12]', body) or re.search(r'\broot\b', body):
            target = (s, e)
            break
if target is None:
    print('NO_TARGET_BLOCK')
    sys.exit(2)

s, e = target
body_lines = lines[s:e+1]

if any('snippets/zittme.conf' in l for l in body_lines):
    print('ALREADY_INCLUDED')
    sys.exit(0)

# location / { ... } 블록 주석 처리 (snippet의 location / 와 중복 방지)
out = []
i = 0
while i < len(body_lines):
    line = body_lines[i]
    if re.match(r'^\s*location\s+/\s*\{', re.sub(r'#.*', '', line)):
        depth = 0
        while i < len(body_lines):
            code = re.sub(r'#.*', '', body_lines[i])
            depth += code.count('{') - code.count('}')
            out.append('#[zittme-setup] ' + body_lines[i])
            i += 1
            if depth <= 0:
                break
        continue
    out.append(line)
    i += 1

# 마지막 닫는 중괄호 앞에 include 삽입
for k in range(len(out) - 1, -1, -1):
    if out[k].strip().startswith('}') or out[k].strip() == '}':
        out.insert(k, '    include snippets/zittme.conf; #[zittme-setup]\n')
        break

lines[s:e+1] = out
with open(path, 'w', encoding='utf-8') as fp:
    fp.writelines(lines)
print('PATCHED')
PYEOF
PYRESULT=$?

if [ $PYRESULT -eq 2 ]; then
    echo "오류: ${DOMAIN} 의 본 server 블록을 찾지 못했습니다. 수동 설정을 이용하세요."
    cp "$BACKUP" "$VHOST"
    exit 1
fi

# 3) 문법 검사 → 실패 시 롤백
if nginx -t 2>&1; then
    systemctl reload nginx 2>/dev/null || service nginx reload
    echo "[3/3] 완료! 설치 화면(또는 관리자 설정)에서 새로고침/재검사하면 mod_rewrite가 OK로 표시됩니다."
else
    echo "nginx 문법 검사 실패 — 원래 설정으로 롤백합니다."
    cp "$BACKUP" "$VHOST"
    nginx -t
    exit 1
fi
