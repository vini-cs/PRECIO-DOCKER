# Imagem: precio-db  |  Função: banco MySQL já inicializado com o setup.sql
FROM mysql:8.0

COPY docker/mysql/precio.cnf /etc/mysql/conf.d/precio.cnf
# Scripts em /docker-entrypoint-initdb.d rodam UMA vez, quando o volume de dados está vazio
COPY back-end/setup.sql /docker-entrypoint-initdb.d/01-setup.sql

# -h 127.0.0.1 (TCP) de propósito: o servidor temporário de inicialização só escuta no socket,
# então o container só fica "healthy" quando o MySQL definitivo estiver no ar.
HEALTHCHECK --interval=5s --timeout=3s --start-period=20s --retries=40 \
    CMD mysqladmin ping -h 127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent || exit 1
