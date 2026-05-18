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
    $conn = new PDO("sqlsrv:server = tcp:liderdriver.database.windows.net,1433; Database = clerioapp", "liderdriver", "6yh^YH7uj8ik");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch (PDOException $e) {
    print("Error connecting to SQL Server.");
    die(print_r($e));
}

- SQL Server Extension Sample Code:
$connectionInfo = array("UID" => "liderdriver", "pwd" => "6yh^YH7uj8ik", "Database" => "clerioapp", "LoginTimeout" => 30, "Encrypt" => 1, "TrustServerCertificate" => 0);
$serverName = "tcp:liderdriver.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionInfo);


## Conexao com banco local
Server=(localdb)\MSSQLLocalDB;Database=app_dev;Integrated Security=true;Encrypt=false;TrustServerCertificate=true;