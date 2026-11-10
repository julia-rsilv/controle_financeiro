# Controle Financeiro Pessoal

API REST para organizar categorias, registrar receitas e despesas e consultar o saldo. Os dados são armazenados em arquivos JSON.

## Requisitos

- PHP 8.3 ou superior, com extensões `json` e `mbstring`
- Composer

## Instalação e execução

Na pasta do projeto, instale as dependências:

```bash
composer install
```

Inicie o servidor local:

```bash
php -S 127.0.0.1:8080 -t public
```

A API fica disponível em `http://127.0.0.1:8080`. Na primeira execução, são criados exemplos em `src/data/categorias.json` e `src/data/transacoes.json`.

As operações `POST` e `PUT` recebem JSON e devem incluir o cabeçalho `Content-Type: application/json`.

## Endpoints

### Categorias

| Método | Caminho | Descrição | Sucesso |
|---|---|---|---|
| `GET` | `/categorias` | Lista as categorias | `200` |
| `GET` | `/categorias/{id}` | Consulta uma categoria | `200` |
| `POST` | `/categorias` | Cadastra uma categoria | `201` |
| `PUT` | `/categorias/{id}` | Atualiza uma categoria | `200` |
| `DELETE` | `/categorias/{id}` | Exclui uma categoria sem lançamentos vinculados | `200` |

Exemplo de categoria:

```json
{
  "nome": "Transporte",
  "tipo": "despesa"
}
```

O nome não pode se repetir, sem distinção entre maiúsculas e minúsculas. O tipo aceita `receita` ou `despesa`. Uma categoria vinculada a lançamentos não pode ser excluída; seu tipo também não pode ser alterado enquanto houver vínculos.

### Lançamentos

| Método | Caminho | Descrição | Sucesso |
|---|---|---|---|
| `GET` | `/lancamentos` | Lista lançamentos; aceita filtros | `200` |
| `GET` | `/lancamentos/{id}` | Consulta um lançamento | `200` |
| `POST` | `/lancamentos` | Cadastra um lançamento | `201` |
| `PUT` | `/lancamentos/{id}` | Atualiza um lançamento | `200` |
| `DELETE` | `/lancamentos/{id}` | Exclui um lançamento | `200` |

Exemplo de lançamento:

```json
{
  "descricao": "Passagem de ônibus",
  "valor": 12.5,
  "data": "2026-11-10",
  "tipo": "despesa",
  "categoria_id": 2
}
```

O valor deve ser maior que zero, a data deve existir e seguir `AAAA-MM-DD`, e `categoria_id` deve identificar uma categoria do mesmo tipo do lançamento.

`GET /lancamentos` aceita estes parâmetros, que podem ser combinados:

| Parâmetro | Exemplo | Uso |
|---|---|---|
| `data_inicio` | `2026-11-01` | Limite inicial do período, inclusive |
| `data_fim` | `2026-11-30` | Limite final do período, inclusive |
| `categoria_id` | `2` | Identificador da categoria |
| `tipo` | `despesa` | `receita` ou `despesa` |

Exemplo:

```text
GET /lancamentos?data_inicio=2026-11-01&data_fim=2026-11-30&categoria_id=2&tipo=despesa
```

### Resumo financeiro

| Método | Caminho | Descrição | Sucesso |
|---|---|---|---|
| `GET` | `/resumo/saldo` | Totais de receitas, despesas e saldo atual | `200` |
| `GET` | `/resumo/periodo?data_inicio=AAAA-MM-DD&data_fim=AAAA-MM-DD` | Totais do período informado | `200` |

O saldo é calculado como receitas menos despesas. As duas datas do resumo por período são obrigatórias e incluem os dias informados.

## Respostas de erro

Os erros seguem o formato JSON:

```json
{
  "erro": "O valor deve ser um número maior que zero.",
  "campos": ["valor"]
}
```

- `400`: campo obrigatório ausente ou requisição sem `Content-Type: application/json`.
- `404`: categoria, lançamento ou endpoint não encontrado.
- `409`: conflito com uma regra de negócio, como nome de categoria repetido ou categoria vinculada.
- `422`: valor, tipo, data ou outro campo inválido.
- `500`: erro inesperado da aplicação ou do armazenamento.

As respostas de exclusão retornam `200` e uma mensagem JSON para manter o formato de resposta da API.

## Testes

Execute a suíte automatizada:

```bash
composer test
```

Também é possível chamar o PHPUnit diretamente:

```bash
php vendor/bin/phpunit
```

Os testes criam arquivos de dados temporários e independentes; não modificam os arquivos usados pela API.

## Armazenamento

Por padrão, os dados ficam em `src/data`. Para usar outro diretório, defina `FINANCE_DATA_DIR` antes de iniciar o servidor.

Linux/macOS:

```bash
FINANCE_DATA_DIR=/caminho/para/dados php -S 127.0.0.1:8080 -t public
```

PowerShell:

```powershell
$env:FINANCE_DATA_DIR = "C:\caminho\para\dados"
php -S 127.0.0.1:8080 -t public
```

Se já houver transações no formato anterior, com o nome da categoria no campo `categoria`, a aplicação converte os registros para `categoria_id` na inicialização.

## Publicação no GitHub

O projeto deve ser publicado em um repositório público. Depois de criar o repositório no GitHub, configure o endereço remoto e envie a branch principal:

```bash
git remote add origin https://github.com/SEU_USUARIO/controle-financeiro.git
git push -u origin main
```
