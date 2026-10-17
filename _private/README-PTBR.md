# UltimateClans Exchange — standalone

## Objetivo

Este pacote e totalmente independente de TribeCraft e pode ser copiado para uma pasta `exchange/` dentro do document root de qualquer site PHP. Nao ha dominio fixo, MySQL, login externo nem chamadas a APIs externas. Basta abrir `https://SEU-DOMINIO/exchange/`.

## Estrutura

```text
exchange/
├── index.php                  # unica pagina PHP publica
├── assets/
│   ├── style.css              # estilo local, sem CDNs
│   └── app.js                 # interface local
├── .htaccess                 # Apache: index e listagem
└── _private/
    ├── .htaccess             # Apache: nega TODOS os acessos HTTP
    ├── config.php            # limites e caminhos relativos
    ├── lib/                  # motor de migracao V8 -> V9
    ├── templates/            # templates originais
    ├── tests/regression.php  # testes CLI
    ├── bin/cleanup.php       # limpeza CLI manual/cron
    ├── bin/router.php        # roteador local de testes (nao para producao)
    └── runtime/              # grava temporarios e resultados
        ├── results/
        ├── uploads/
        └── work/
```

## Requisitos

- PHP 8.1+; extensao `zip` (`ZipArchive`) obrigatoria.
- `pdo_sqlite` necessaria para migracao de SQLite. Sem ela, migre apenas configuracoes; armazenamento SQLite nao sera convertido com seguranca.
- `session`, `json`, `fileinfo` e filesystem gravavel (padrao em PHP).
- A pasta `_private/runtime/` e subpastas devem ser gravaveis pelo usuario do PHP.
- Configurar `upload_max_filesize=110M`, `post_max_size=120M`, `max_execution_time=300`. Recomenda-se `memory_limit=512M`, `max_input_time=300`.
- Nginx: `client_max_body_size 110m;` e, em PHP-FPM, validar `fastcgi_read_timeout 300s;` e `request_terminate_timeout` adequados.

## Instalacao basica (Apache)

1. Extraia o pacote, mantendo a pasta `exchange/` dentro do document root. Exemplo: `/var/www/html/exchange`.
2. Confirme que Apache permite as regras `.htaccess` (AllowOverride). O arquivo `_private/.htaccess` deve negar qualquer acesso a `_private/`.
3. Ajuste a permissao de `_private/runtime/` para o usuario do servidor PHP (e.g. `www-data`).
4. Acesse `https://SEU-DOMINIO/exchange/`, envie um ZIP de V8 e baixe o resultado.
5. Cheque obrigatoriamente que `https://SEU-DOMINIO/exchange/_private/config.php` e `https://SEU-DOMINIO/exchange/_private/templates/v8/config.yml` retornam 403/404. Se retornar 200, NAO abra ao publico: ajuste o servidor.

Se `AllowOverride None` estiver ativo, `.htaccess` sera ignorado; configure bloqueio direto no VirtualHost/Directory do Apache antes de expor.

## Nginx — bloqueio obrigatorio

O Nginx NAO interpreta `.htaccess`. Insira no `server { ... }` existente (antes de habilitar acesso publico):

```nginx
# Nao permitir acesso ao motor, templates, ZIPs nem relatorios internos.
location ^~ /exchange/_private/ {
    return 404;
}

# Entrada de PHP do aplicativo, ajuste socket/host do PHP-FPM.
location = /exchange/index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/exchange/index.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_read_timeout 300s;
}

location /exchange/ {
    index index.php;
    try_files $uri $uri/ =404;
}
```

Observe: o servidor existente deve usar `root` que englobe `/exchange/`. O socket e so exemplo (`php8.3`): adapte a versao instalada. Uma configuracao global ja existente de PHP pode precisar de ajuste; **nao use a pasta em producao sem validar a negacao HTTP de `_private/`**. Para bloquear PHP inesperado, garanta que so `/exchange/index.php` sera executado.

Para Nginx, configuracoes PHP sugeridas (nos arquivos `php.ini` de FPM):

```ini
upload_max_filesize = 110M
post_max_size = 120M
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
```

## Exemplo de instalacao por SSH em Ubuntu

**Ajuste o document root e o arquivo de ZIP antes de executar**:

```bash
sudo mkdir -p /var/www/html
sudo unzip -o exchange-standalone.zip -d /var/www/html/
sudo chown -R www-data:www-data /var/www/html/exchange/_private/runtime
sudo find /var/www/html/exchange/_private/runtime -type d -exec chmod 700 {} \;
sudo find /var/www/html/exchange/_private/runtime -type f -exec chmod 600 {} \;
php -m | grep -E 'zip|pdo_sqlite'
php /var/www/html/exchange/_private/tests/regression.php
```

Se o usuario PHP-FPM for outro, use esse usuario no lugar de `www-data`. No Nginx, adicione o bloqueio acima na configuracao real e execute `sudo nginx -t && sudo systemctl reload nginx`.

## Seguranca e privacidade

- Nenhum login/conta externa. O POST e protegido por CSRF via sessao local.
- Downloads e relatorios usam identificadores aleatorios (bearer tokens); quem possuir o link podera baixar ate expirar.
- Arquivos gerados expiram em 60 minutos por padrao (limpeza durante acessos ou via cron).
- Limites de conversao padrao: maximo 3 tentativas por IP a cada 10 minutos e 30 globalmente por hora. Apenas uma conversao simultanea.
- As regras de limite usam `REMOTE_ADDR`, sem aceitar `X-Forwarded-For` enviado pelo cliente. Atras de proxy/CDN, configure IP real **no webserver** se necessario.
- Nao expose `_private/` em HTTP. O arquivo `.htaccess` nao protege Nginx. Evite backups e copia de arquivos privados publicamente.
- A pasta `_private/runtime/work` e removida ao final de conversoes bem-sucedidas e falhas; sobras de processos interrompidos sao removidas depois de 2 horas.
- Nao ha garantia de compatibilidade perfeita de todo arquivo V8; leia o relatorio antes de colocar a saida em um servidor real.

## Configuracoes

Edite `_private/config.php` para ajustar tamanho maximo, TTL e limites. Os caminhos sao baseados em `__DIR__`, nao no dominio nem na URL.

## Rotina de limpeza (opcional)

```bash
php /var/www/html/exchange/_private/bin/cleanup.php
```

Exemplo cron a cada 10 minutos (edite via `sudo crontab -e` ou crontab do usuario PHP):

```cron
*/10 * * * * /usr/bin/php /var/www/html/exchange/_private/bin/cleanup.php >/dev/null 2>&1
```

## Teste local com o PHP embutido

Na pasta que contem `exchange/` (nao dentro dela):

```bash
php -S 127.0.0.1:8080 -t . exchange/_private/bin/router.php
```

Abra `http://127.0.0.1:8080/exchange/`. Esse roteador permite apenas a interface e os assets; **nao** use o servidor PHP embutido em producao.

## Verificacoes basicas

- `/exchange/` abre a interface sem login.
- `/exchange/assets/style.css` carrega sem depender de CDN.
- `/exchange/_private/config.php` e `/exchange/_private/templates/v8/config.yml` retornam 403/404.
- Requisicoes POST sem token CSRF valido sao rejeitadas.
- ZIP V8 de exemplo converte; ZIP e relatorio sao baixaveis; links expiram.
- `php .../_private/tests/regression.php` termina sem falhas.
