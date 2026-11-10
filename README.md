# API de Controle Financeiro Pessoal

Desenvolvi esta API REST em PHP para cadastrar receitas e despesas, organizá-las por categorias e consultar saldo e totais por período. A API não usa banco de dados nem cria uma pasta `storage/`; cada cliente mantém seus dados em uma sessão PHP identificada por cookie.

## Requisitos

- PHP 8 ou superior

## Como iniciar

1. Abra um terminal na pasta principal do projeto.
2. Inicie o servidor PHP:

```bash
php -S localhost:8080 -t public public/index.php
```

Mantenha o terminal aberto. A API fica disponível em `http://localhost:8080`. Use sempre o mesmo cliente/cookie para acessar os dados criados na sessão. O Postman mantém os cookies no gerenciador de cookies da aplicação. As sessões são armazenadas pelo PHP no diretório temporário configurado no servidor, fora deste projeto, e podem expirar conforme a configuração do PHP.

## Testes no Postman

Use `http://localhost:8080` como endereço base. Nas requisições com corpo, selecione **Body → raw → JSON** e envie o cabeçalho `Content-Type: application/json`.

Faça os testes nesta ordem:

1. Criar uma categoria: `POST http://localhost:8080/categorias`

```json
{
  "nome": "Salário",
  "tipo": "receita"
}
```

Anote o `id` da resposta.

2. Listar categorias: `GET http://localhost:8080/categorias`
3. Consultar a categoria criada: `GET http://localhost:8080/categorias/1`
4. Criar um lançamento: `POST http://localhost:8080/lancamentos`

```json
{
  "descricao": "Salário mensal",
  "valor": 3500.00,
  "data": "2026-11-05",
  "tipo": "receita",
  "categoria_id": 1
}
```

Substitua `1` pelo ID real da categoria. O tipo do lançamento deve corresponder ao tipo da categoria.

5. Listar lançamentos: `GET http://localhost:8080/lancamentos`
6. Consultar saldo: `GET http://localhost:8080/resumo/saldo`
7. Consultar totais do período: `GET http://localhost:8080/resumo/periodo?data_inicial=2026-11-01&data_final=2026-11-30`

## Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| POST | `/categorias` | Cadastrar categoria |
| GET | `/categorias` | Listar categorias |
| GET | `/categorias/{id}` | Consultar categoria |
| PUT | `/categorias/{id}` | Atualizar categoria |
| DELETE | `/categorias/{id}` | Excluir categoria sem lançamentos vinculados |
| POST | `/lancamentos` | Cadastrar lançamento |
| GET | `/lancamentos` | Listar lançamentos |
| GET | `/lancamentos/{id}` | Consultar lançamento |
| PUT | `/lancamentos/{id}` | Atualizar lançamento |
| DELETE | `/lancamentos/{id}` | Excluir lançamento |
| GET | `/resumo/saldo` | Consultar receitas, despesas e saldo atual |
| GET | `/resumo/periodo?data_inicial=AAAA-MM-DD&data_final=AAAA-MM-DD` | Consultar totais do período |

É possível combinar filtros em `GET /lancamentos`:

- `?tipo=despesa`
- `?categoria_id=2`
- `?data_inicial=2026-11-01&data_final=2026-11-30`
- `?tipo=despesa&categoria_id=2&data_inicial=2026-11-01&data_final=2026-11-30`

## Validações e respostas

- Os tipos aceitos são `receita` e `despesa`.
- O valor precisa ser maior que zero e ter até duas casas decimais válidas.
- A categoria precisa existir e ter o mesmo tipo do lançamento.
- Os nomes das categorias não podem se repetir.
- A data é obrigatória, válida e segue o formato `AAAA-MM-DD`.
- Não é permitido excluir uma categoria que tenha lançamentos vinculados.
- As requisições com corpo e as respostas da API usam JSON.

Códigos HTTP usados pela API:

- `200`: operação realizada.
- `201`: recurso criado.
- `204`: requisição OPTIONS sem conteúdo.
- `400`: JSON inválido.
- `404`: rota ou recurso não encontrado.
- `409`: conflito, como categoria duplicada ou vinculada.
- `422`: dados inválidos ou regra de negócio violada.
- `500`: erro interno ao processar ou gravar os dados.

## Testes automatizados

Os testes de integração em `tests/test.php` verificam os códigos HTTP e o conteúdo JSON das respostas, incluindo cenários de sucesso, dados inválidos e identificadores inexistentes. Eles mantêm um cookie próprio, separado das sessões usadas pelo Postman.

Inicie o servidor:

```bash
php -S localhost:8080 -t public public/index.php
```

Em outro terminal, execute:

```bash
API_URL=http://localhost:8080 php tests/test.php
```

No PowerShell:

```powershell
$env:API_URL = "http://localhost:8080"
php tests/test.php
```

Os testes usam nomes únicos e removem os registros criados ao terminar.

## Estrutura do projeto

```text
├── public/
│   └── index.php
├── src/
│   └── app.php
├── tests/
│   └── test.php
└── README.md
```

## Publicação no GitHub

Depois de criar o repositório, publique o código com:

```bash
git init
git add .
git commit -m "Implementa API de controle financeiro"
git branch -M main
git remote add origin https://github.com/SEU-USUARIO/SEU-REPOSITORIO.git
git push -u origin main
```

Os dados das sessões não são armazenados dentro do projeto.
