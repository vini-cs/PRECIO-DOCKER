#!/usr/bin/env python3
"""
scraper.py - Busca preços de produtos na Pichau.com.br
Uso: python scraper.py "nome do produto"
Retorna JSON com os resultados encontrados.
"""

import sys
import json
import urllib.request
import urllib.parse
import urllib.error


def buscar_pichau(query: str, quantidade: int = 12) -> dict:
    """
    Busca produtos na Pichau via API GraphQL interna (Magento 2).
    Retorna dict com lista de produtos ou mensagem de erro.
    """

    url = "https://pichau.com.br/graphql"

    graphql_query = """
    query SearchProducts($search: String!, $pageSize: Int!) {
      products(search: $search, pageSize: $pageSize, sort: { relevance: DESC }) {
        total_count
        items {
          name
          sku
          url_key
          price_range {
            minimum_price {
              final_price {
                value
                currency
              }
              regular_price {
                value
              }
              discount {
                percent_off
                amount_off
              }
            }
          }
          small_image {
            url
            label
          }
          stock_status
        }
      }
    }
    """

    payload = json.dumps({
        "query": graphql_query,
        "variables": {
            "search": query,
            "pageSize": quantidade
        }
    }).encode("utf-8")

    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/124.0.0.0 Safari/537.36"
        ),
        "Origin": "https://www.pichau.com.br",
        "Referer": "https://www.pichau.com.br/",
        "Store": "default",
    }

    req = urllib.request.Request(url, data=payload, headers=headers, method="POST")

    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            raw = response.read().decode("utf-8")
            data = json.loads(raw)
    except urllib.error.HTTPError as e:
        return {"erro": f"Erro HTTP {e.code}: {e.reason}"}
    except urllib.error.URLError as e:
        return {"erro": f"Erro de conexão: {e.reason}"}
    except Exception as e:
        return {"erro": f"Erro inesperado: {str(e)}"}

    # Verifica erros GraphQL
    if "errors" in data:
        msgs = [err.get("message", "Erro desconhecido") for err in data["errors"]]
        return {"erro": "GraphQL: " + " | ".join(msgs)}

    items = data.get("data", {}).get("products", {}).get("items", [])
    total = data.get("data", {}).get("products", {}).get("total_count", 0)

    if not items:
        return {"total": 0, "produtos": [], "mensagem": "Nenhum produto encontrado."}

    produtos = []
    for item in items:
        preco_info  = item.get("price_range", {}).get("minimum_price", {})
        preco_final = preco_info.get("final_price", {}).get("value", 0)
        preco_orig  = preco_info.get("regular_price", {}).get("value", 0)
        desconto    = preco_info.get("discount", {}).get("percent_off", 0)

        url_produto = f"https://www.pichau.com.br/{item.get('url_key', '')}"

        img = item.get("small_image", {})
        imagem_url = img.get("url", "") if img else ""

        em_estoque = item.get("stock_status", "OUT_OF_STOCK") == "IN_STOCK"

        produtos.append({
            "nome":       item.get("name", ""),
            "sku":        item.get("sku", ""),
            "preco":      preco_final,
            "preco_orig": preco_orig if preco_orig and preco_orig != preco_final else None,
            "desconto":   round(desconto, 1) if desconto else None,
            "url":        url_produto,
            "imagem":     imagem_url,
            "em_estoque": em_estoque,
        })

    return {
        "total":    total,
        "produtos": produtos,
    }


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"erro": "Uso: python scraper.py \"nome do produto\""}))
        sys.exit(1)

    termo = " ".join(sys.argv[1:])

    if len(termo.strip()) < 2:
        print(json.dumps({"erro": "Pesquisa muito curta. Digite pelo menos 2 caracteres."}))
        sys.exit(1)

    resultado = buscar_pichau(termo)
    print(json.dumps(resultado, ensure_ascii=False))