#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo "Execute com sudo no iaserver."; exit 1; }
[[ $# -ge 1 ]] || { echo 'Uso: sudo bash backend/deploy/authorize-tunnel.sh "ssh-ed25519 AAAA... comentario"'; exit 1; }
KEY="$*"
[[ "$KEY" =~ ^ssh-ed25519[[:space:]][A-Za-z0-9+/=]+([[:space:]].*)?$ ]] || {
    echo "Esperada uma chave pública ssh-ed25519 válida."; exit 1;
}
USER_NAME=isp-ia-tunnel
if ! id "$USER_NAME" >/dev/null 2>&1; then
    useradd --system --create-home --home-dir /var/lib/isp-ia-tunnel --shell /bin/sh "$USER_NAME"
fi
install -d -o "$USER_NAME" -g "$USER_NAME" -m 700 /var/lib/isp-ia-tunnel/.ssh
printf 'restrict,port-forwarding,permitopen="127.0.0.1:8787",command="/bin/false" %s\n' "$KEY" \
    >/var/lib/isp-ia-tunnel/.ssh/authorized_keys
chown "$USER_NAME:$USER_NAME" /var/lib/isp-ia-tunnel/.ssh/authorized_keys
chmod 600 /var/lib/isp-ia-tunnel/.ssh/authorized_keys
echo "Usuário limitado de túnel SSH configurado. Somente encaminhamento para 127.0.0.1:8787."
