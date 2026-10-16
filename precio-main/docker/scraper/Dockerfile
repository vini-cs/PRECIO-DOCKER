# Imagem: precio-scraper  |  Função: executa o scraper.py (somente biblioteca padrão do Python)
FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    TZ=America/Sao_Paulo

WORKDIR /app
COPY back-end/scraper.py /app/scraper.py

RUN useradd --system --uid 1001 scraper && chown -R scraper /app
USER scraper

# Container fica ativo; uso:  docker exec precio-scraper python scraper.py "rtx 4070"
CMD ["sleep", "infinity"]
