#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo "Execute com sudo."; exit 1; }
command -v ssh >/dev/null || { echo "Instale openssh-client."; exit 1; }
install -d -o root -g root -m 700 /etc/isp-ia-web
KEY=/etc/isp-ia-web/tunnel_ed25519
if [[ ! -f "$KEY" ]]; then
    ssh-keygen -q -t ed25519 -N '' -f "$KEY"
    chmod 600 "$KEY"
fi
cat >/etc/systemd/system/isp-ia-tunnel.service <<'EOF'
[Unit]
Description=Tunel SSH do painel aaPanel para a IA local
Wants=network-online.target
After=network-online.target
[Service]
Type=simple
ExecStart=/usr/bin/ssh -N -T -i /etc/isp-ia-web/tunnel_ed25519 -o BatchMode=yes -o StrictHostKeyChecking=yes -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -L 127.0.0.1:18787:127.0.0.1:8787 isp-ia-tunnel@100.68.68.6
Restart=always
RestartSec=8
NoNewPrivileges=yes
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
echo
echo "Chave pública do aaPanel (copie esta linha para o instalador remoto do iaserver):"
cat "$KEY.pub"
echo
echo "Depois de autorizar a chave no iaserver, valide a identidade SSH interativamente:"
echo "  sudo ssh -N -T -i $KEY -o StrictHostKeyChecking=ask -L 127.0.0.1:18787:127.0.0.1:8787 isp-ia-tunnel@100.68.68.6"
echo "Confira a impressão digital do host, aceite somente se corresponder ao servidor real, pressione Ctrl+C."
echo "Em seguida:"
echo "  sudo systemctl enable --now isp-ia-tunnel"
echo "  curl -fsS http://127.0.0.1:18787/health"
echo "A porta 8787 continua inacessível pela rede; somente o túnel local é exposto."
