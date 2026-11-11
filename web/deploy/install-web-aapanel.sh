#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo "Use sudo."; exit 1; }
[[ $# == 1 ]] || { echo "Uso: sudo bash web/deploy/install-web-aapanel.sh /www/wwwroot/ia.minastech.com.br"; exit 1; }
SITE="$(printf '%s' "$1" | sed 's@/$@@')"
[[ "$SITE" == /www/wwwroot/* ]] || { echo "Por segurança, informe um diretório em /www/wwwroot"; exit 1; }
BASE="$(cd "$(dirname "$0")/../.." && pwd)"
PHP_BIN="$(command -v php || true)"
if [[ -z "$PHP_BIN" ]]; then
    for p in /www/server/php/84/bin/php /www/server/php/83/bin/php /www/server/php/82/bin/php /www/server/php/81/bin/php /www/server/php/80/bin/php; do
        [[ -x "$p" ]] && { PHP_BIN="$p"; break; }
    done
fi
[[ -n "$PHP_BIN" ]] || { echo "PHP CLI não localizado. Instale PHP 8+ no aaPanel."; exit 1; }
"$PHP_BIN" -r 'if(PHP_MAJOR_VERSION < 8) exit(1);' || { echo "PHP 8+ é necessário."; exit 1; }
WEB_USER=www
id www >/dev/null 2>&1 || WEB_USER=www-data
id "$WEB_USER" >/dev/null 2>&1 || { echo "Usuário PHP www/www-data não encontrado."; exit 1; }

install -d -o root -g "$WEB_USER" -m 750 "$SITE/private"
install -d -o "$WEB_USER" -g "$WEB_USER" -m 750 "$SITE/private/attempts"
install -d -m 755 "$SITE/public"
if [[ -f "$SITE/public/index.php" ]] && ! grep -q 'ISP-IA-PANEL' "$SITE/public/index.php"; then
    echo "ABORTADO: index.php existente não pertence ao projeto. Nenhum arquivo alterado."; exit 1
fi
install -o root -g root -m 644 "$BASE/web/public/index.php" "$SITE/public/index.php"
if [[ ! -f "$SITE/private/config.php" ]]; then
    printf 'Usuário do painel [admin]: '
    read -r PANEL_USER
    if [[ -z "$PANEL_USER" ]]; then PANEL_USER=admin; fi
    [[ "$PANEL_USER" =~ ^[A-Za-z0-9_-]{3,32}$ ]] || { echo "Usuário inválido"; exit 1; }
    printf 'Nova senha do painel (mínimo 14 caracteres): '
    read -rs PANEL_PASS; echo
    [[ "$(printf '%s' "$PANEL_PASS" | wc -c)" -ge 14 ]] || { echo "Senha muito curta"; exit 1; }
    printf 'Cole o IA_ADMIN_TOKEN do iaserver (será ocultado): '
    read -rs PANEL_TOKEN; echo
    [[ "$(printf '%s' "$PANEL_TOKEN" | wc -c)" -ge 32 ]] || { echo "Token inválido"; exit 1; }
    HASH="$(printf '%s' "$PANEL_PASS" | "$PHP_BIN" -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);')"
    export PANEL_USER PANEL_TOKEN HASH
    "$PHP_BIN" -r '
        $config = ["username"=>getenv("PANEL_USER"),
                   "password_hash"=>getenv("HASH"),
                   "backend_token"=>getenv("PANEL_TOKEN")];
        $php = "<?php\nreturn ".var_export($config, true).";\n";
        if (file_put_contents($argv[1], $php, LOCK_EX) === false) exit(1);
    ' "$SITE/private/config.php"
    unset PANEL_PASS PANEL_TOKEN HASH
    chmod 640 "$SITE/private/config.php"
    chown root:"$WEB_USER" "$SITE/private/config.php"
else
    echo "Configuração existente preservada; credenciais NÃO foram modificadas."
fi
"$PHP_BIN" -l "$SITE/public/index.php"
echo
echo "Painel copiado para $SITE/public."
echo "No aaPanel, crie site separado com DOCUMENT ROOT = $SITE/public, PHP 8+ e HTTPS."
echo "Mantenha $SITE/private fora da raiz HTTP e libere leitura do diretório private no open_basedir desse site."
echo "O túnel SSH local deve escutar em 127.0.0.1:18787 no servidor aaPanel."
echo "NÃO exponha a porta 8787 do iaserver."
