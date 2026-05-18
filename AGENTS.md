IMPORTANTE
- Antes de codificar qualquer alteracao, sempre apresentar um plano detalhado.
- Aguardar aprovacao explicita do plano pelo usuario antes de iniciar implementacao.
- Nao executar alteracoes de codigo sem aprovacao, mesmo que a tarefa pareça simples.
- Priorizar codificaçao simples, bibliotecas/frameworks/etc gratis.
- Imdependente da linguagem usada, suas respostas sempre serão em Portugues Brasil.
- Sermpre que haver um campo de data ele deve ser do tipo date e abrir o calendario dara informar a data.
- Todas as datas devem usar o formato DD/MM/AA, tanto para preenchimento quanto para visualizacao.
- Nunca sugira para eu modificar algo no codigo; se algo precisa ser mudado voce deve altera-lo.
- Sempre liste os arquivos criados/modificados.
- Na criacao de tabelas usar o prefixo "tb" + um sequencial, e o nome, exemplo: (tb1_nome_nome), sempre verifique as tabelas para nao duplicar o sequencial.
- Priorizar performanca com indices no banco de dados

PADRAO VISUAL OBRIGATORIO:
- Botoes e badges de loja devem sempre usar as cores primarias predefinidas centralmente no codigo.
- Botoes e badges de funcao devem sempre usar as cores primarias predefinidas centralmente no codigo.
- Badge de nome de usuario deve sempre usar texto preto com fundo branco.
- Sempre formatar quando mostrar os dados como CPF(###.###.###-##), CNPJ(##.###.###/####-##), Data(DD/MM/YY), telefone((##)# ####-####)

CONSULTA NO BANCO DE DADOS:
- Para verificaçao de bugs as consulta devem ser feitas em produçao:



## Conexao com banco de dados de produçao
- PHP Data Objects(PDO) Sample Code:
try {
    $conn = new PDO(
        "sqlsrv:server = tcp:liderdriver.database.windows.net,1433; Database = clerioapp",
        getenv("LIDERDRIVER_DB_USER"),
        getenv("LIDERDRIVER_DB_PASS")
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch (PDOException $e) {
    print("Error connecting to SQL Server.");
    die(print_r($e));
}

- SQL Server Extension Sample Code:
$connectionInfo = array(
    "UID" => getenv("LIDERDRIVER_DB_USER"),
    "pwd" => getenv("LIDERDRIVER_DB_PASS"),
    "Database" => "clerioapp",
    "LoginTimeout" => 30,
    "Encrypt" => 1,
    "TrustServerCertificate" => 0
);
$serverName = "tcp:liderdriver.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionInfo);


## Conexao com banco local
Server=(localdb)\MSSQLLocalDB;Database=app_dev;Integrated Security=true;Encrypt=false;TrustServerCertificate=true;

## Google
ID do cliente
955736336306-0rovunqpcs0er6o1dog9360mh57tbcv4.apps.googleusercontent.com

Chave secreta do cliente
definir-em-configuracao-segura-local

JSON
{"web":{"client_id":"955736336306-0rovunqpcs0er6o1dog9360mh57tbcv4.apps.googleusercontent.com","project_id":"aguiasdelivery","auth_uri":"https://accounts.google.com/o/oauth2/auth","token_uri":"https://oauth2.googleapis.com/token","auth_provider_x509_cert_url":"https://www.googleapis.com/oauth2/v1/certs","client_secret":"definir-em-configuracao-segura-local","javascript_origins":["https://liderdriver.azurewebsites.net"]}}
