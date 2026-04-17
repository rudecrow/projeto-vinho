<?php declare(strict_types=1);

// ---- Bootstrap / erros (DEV) ----
ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // mysqli lança exceções

<?php declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$port = (int)(getenv('MYSQLPORT') ?: 3306);
$user = getenv('MYSQLUSER') ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: '';
$dbname = getenv('MYSQLDATABASE') ?: 'railway';

$conn = new mysqli($host, $user, $password, $dbname, $port);
$conn->set_charset('utf8mb4');

// ---- Helpers base ----
function num($val): float {
    if ($val === null) return 0.0;
    if (is_string($val)) {
        $v = trim($val);
        if ($v === '') return 0.0;
        $v = str_replace([' ', "\xC2\xA0"], '', $v);
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float)$v : 0.0;
    }
    return is_numeric($val) ? (float)$val : 0.0;
}
function checkbox01(string $name): int {
    return isset($_POST[$name]) && $_POST[$name] === '1' ? 1 : 0;
}
function strf(string $name): string {
    return isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
}

/**
 * Processa linhas de veículos (viticultura/transportes).
 * $rows: array com chaves [veiculo, combustivel, modo, distancia, litros, kwh]
 * $elec_origin: origem elétrica selecionada no formulário (para EV)
 * $COMB_FATORES e fatores elétricos vêm do escopo atual.
 */
function process_vehicle_rows(array $rows, string $elec_origin, array $COMB_FATORES,
                              float $EF_GRID_MISTA, array $MAP_FATOR_ELEC): array {
    $tot = [
        'km' => 0.0, 'litros' => 0.0, 'kwh' => 0.0,
        'emissao' => 0.0
    ];
    $fator_ev = $MAP_FATOR_ELEC[$elec_origin] ?? $EF_GRID_MISTA;


    foreach (array_slice($rows, 0, 3) as $r) {
        $veic = isset($r['veiculo']) ? (string)$r['veiculo'] : '';
        $comb = isset($r['combustivel']) ? (string)$r['combustivel'] : '';
        $modo = isset($r['modo']) ? (string)$r['modo'] : '';
        $km   = num($r['distancia'] ?? null);
        $lt   = num($r['litros'] ?? null);
        $kwh  = num($r['kwh'] ?? null);

        if (norm_key($veic) === 'eletrico') {
            // elétrico: ignora km/litros; usa kWh × fator_ev
            $tot['kwh']     += $kwh;
            $tot['emissao'] += $kwh * $fator_ev;
        } else {
            // combustão: converte km -> litros se for o caso; emite via fator de combustível
            $litros = litros_from_modo($modo, $km, $lt, $veic, $comb);
            $fator  = $COMB_FATORES[norm_key($comb)] ?? 0.0;
            $tot['km']     += ($modo === 'km') ? $km : 0.0;
            $tot['litros'] += $litros;
            $tot['emissao'] += $fator * $litros;
        }
    }
    return $tot;
}

// ---- Normalização (sem depender de mbstring) ----
function lower_pt(string $s): string {
    $s = trim($s);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function norm_key(string $s): string {
    $s = lower_pt($s);
    // inclui maiúsculas acentuadas para funcionar sem mbstring
    $map = [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u',
        'ç'=>'c',
        'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
        'É'=>'e','Ê'=>'e','È'=>'e','Ë'=>'e',
        'Í'=>'i','Ì'=>'i','Ï'=>'i',
        'Ó'=>'o','Ô'=>'o','Õ'=>'o','Ò'=>'o','Ö'=>'o',
        'Ú'=>'u','Ù'=>'u','Ü'=>'u',
        'Ç'=>'c'
    ];
    return strtr($s, $map);
}
function normalize_keys(array $arr): array {
    $out = [];
    foreach ($arr as $k => $v) $out[norm_key((string)$k)] = $v;
    return $out;
}

// ---- Consumos e conversões ----
function estimativa_l_100km(string $veiculo, string $combustivel): float {
    $v = norm_key($veiculo);
    $c = norm_key($combustivel);

    // aceita "trator/tractor", "camiao/caminhao"
    $base = match ($v) {
        'carro'                 => 6.5,
        'carrinha'              => 8.5,
        'trator', 'tractor'     => 12.0,
        'camiao', 'caminhao'    => 28.0,
        'maquina de vinificacao' => 15.0,
        default                 => 8.0,
    };

    $ajuste = 0.0;
    if ($c === 'gasolina') $ajuste = +1.0;
    if ($c === 'gpl')      $ajuste = +1.5;

    return max(3.5, $base + $ajuste);
}


// --- Função utilitária segura para ler floats do POST ---
function post_float($name, $default = 0.0) {
    if (!isset($_POST[$name])) return $default;
    $v = $_POST[$name];
    // Remove vírgulas e espaços, troca vírgula por ponto se necessário
    $v = trim(str_replace(',', '.', str_replace(' ', '', $v)));
    if ($v === '') return $default;
    return floatval($v);
}




// --- Função utilitária para ler strings do POST ---
function post_str($name, $default = '') {
    return isset($_POST[$name]) ? trim($_POST[$name]) : $default;
}

function litros_from_modo(string $modo, float $km, float $litros, string $veiculo, string $combustivel): float {
    if ($modo === 'km') {
        $l_100 = estimativa_l_100km($veiculo, $combustivel);
        return ($km > 0.0) ? ($km * $l_100) / 100.0 : 0.0;
    }
    return max(0.0, $litros);
}



// === Eletricidade (kg CO2e / kWh) ===
// Rede pública (mix nacional / não renovável)
define('FE_ELEC_REDE',  0.3024); // usa o que já tinhas para "não renovável" 0.3024usar este valor pq e o portugues
// Renovável on-site (autoconsumo) — aproximação típica
define('FE_ELEC_SOLAR',  0.055);  // 0.055podes alinhar com o teu EF_AUTOCONSUMO se preferires
define('FE_ELEC_EOLICA', 0.007);  //0.007 idem; separa se tiveres fator próprio

// Fatores para eletricidade de veículos (EV)
$EF_GRID_MISTA = FE_ELEC_REDE; // usar o da rede pública como "misto" por omissão
$MAP_FATOR_ELEC = [
  'rede_mista'   => FE_ELEC_REDE,
  'nao_renovavel'=> FE_ELEC_REDE,
  'rede'         => FE_ELEC_REDE,
  'autoconsumo'  => FE_ELEC_SOLAR,
  'solar'        => FE_ELEC_SOLAR,
  'eolica'       => FE_ELEC_EOLICA,
];


// ---- Fatores de combustível (kg CO2e / L) ----
$combustivel_fatores = [
    'Gasoleo'        => 2.7,
    'Gasoleo Verde'  => 2.7,
    'Gasolina'       => 3.24,
    'GPL'            => 1.48,
    'Gás Natural'    => 1.67,
    'Gás Propano'    => 1.51,
];
$COMB_FATORES = normalize_keys($combustivel_fatores);

$vinificacao_eletricidade_origem     = norm_key(post_str('eletricidade_origem', 'autoconsumo'));
$engarrafamento_eletricidade_origem  = norm_key(post_str('engarrafamento_eletricidade_origem', 'autoconsumo'));

// ===== FATORES DE EMBALAGEM (kg CO2e / kg) =====
// NOTA: valores EXEMPLO — substitui pelos teus quando os tiveres.
// Vidro (produção primária) ~1–1.5; Alumínio pode ser alto se não for reciclado, etc.
$EF_MATERIAL_GARRAFA = [
  'Vidro Branco' => 0.791,   
  'Vidro Escuro' => 0.810,   
  'Bag-in-box'   => 0.725,  
  'PET bottle'   => 3.400 ,
];

$EF_ROTULO = [
  'Papel de impressão' => 2.930,
  'Papel/cartao'    => 1.426,   
  'filme plastico'  => 5.500 ,  
];

$EF_ROLHA = [
  'rolha cortiça natural' => 1.370,
  'rolha sintetica/técnica'   => 2.200 ,  
  'tampa de Alumínio'       => 8.950,  
];




//etapas
// --------------------- VITICULTURA ---------------------
$veiculo_vit          = strf('veiculo_vit');
$combustivel_tipo_vit = strf('combustivel_tipo_vit');

$modo_consumo_vit             = strf('modo_consumo_vit'); // km | litros
$distancia_vit                = num($_POST['distancia_vit'] ?? null);
$combustivel_qtd_vit          = num($_POST['combustivel_qtd_vit'] ?? null);
$eletricidade_veiculo_kwh_vit = num($_POST['eletricidade_veiculo_kwh_vit'] ?? null);
$agua_vit                     = num($_POST['agua_vit'] ?? null);
$area_vinha                   = num($_POST['area_vinha'] ?? null);

// Herda a origem escolhida em "Vinificação"
$eletricidade_origem          = strf('eletricidade_origem'); 

// ---- Sequestro de carbono ----
$sequestro_nivel = strf('sequestro_nivel'); 
$sequestro_valor = num($_POST['sequestro_valor'] ?? null);

// Níveis permitidos
$permitidos = ['nao_considerar', 'muito_baixo', 'baixo', 'medio', 'alto', 'muito_elevado'];
if (!in_array($sequestro_nivel, $permitidos, true)) {
    $sequestro_nivel = '';
}

// Intervalos (t CO₂e/ha·ano) ou valor fixo
$mapa = [
    'nao_considerar' => [0.0, 0.0], // valor fixo
    'muito_baixo'   => [0.01, 0.01], // valor fixo
    'baixo'         => [0.10, 0.15],
    'medio'         => [0.16, 0.35],
    'alto'          => [0.40, 0.80],
    'muito_elevado' => [1.00, 1.35],
];

$sequestro_co = 0.0;

if ($sequestro_nivel) {
    [$mn, $mx] = $mapa[$sequestro_nivel];

    if ($mn === $mx) {
        // Valor fixo (ex.: muito_baixo)
        $sequestro_co = $mn;
    } else {
        // Se o utilizador não introduziu valor, usar o ponto médio
        if ($sequestro_valor === 0.0) {
            $sequestro_co = ($mn + $mx) / 2.0;
        } else {
            // Validação dos intervalos
            if ($sequestro_valor < $mn || $sequestro_valor > $mx) {
                die("Valor de sequestro ($sequestro_nivel) fora do intervalo $mn–$mx t CO₂e/ha·ano");
            }
            $sequestro_co = $sequestro_valor;
        }
    }
} else {
    $sequestro_co = 0.0;
}


// Emissões do veículo na Viticultura (suporta linhas repetíveis)
$combustivel_emissao_vit = 0.0;
$eletricidade_veiculo_kwh_vit = num($_POST['eletricidade_veiculo_kwh_vit'] ?? null); // legado
$combustivel_qtd_vit = num($_POST['combustivel_qtd_vit'] ?? null);                    // legado
$distancia_vit = num($_POST['distancia_vit'] ?? null);                                // legado

if (!empty($_POST['veiculos_vit']) && is_array($_POST['veiculos_vit'])) {
    $totVit = process_vehicle_rows($_POST['veiculos_vit'], $eletricidade_origem, $COMB_FATORES,
                                   $EF_GRID_MISTA, $MAP_FATOR_ELEC);
    // sobrescreve pelos totais
    $distancia_vit                 = $totVit['km'];
    $combustivel_qtd_vit           = $totVit['litros'];
    $eletricidade_veiculo_kwh_vit  = $totVit['kwh'];
    $combustivel_emissao_vit       = $totVit['emissao'];
} else {
    // --- caminho legado (campos simples) ---
    if (norm_key($veiculo_vit) === 'eletrico') {
        $fator_ev = $MAP_FATOR_ELEC[$eletricidade_origem] ?? $EF_GRID_MISTA;
        $combustivel_emissao_vit = $eletricidade_veiculo_kwh_vit * $fator_ev;
        $combustivel_qtd_vit = 0.0;
    } else {
        $litros_calc_vit     = litros_from_modo($modo_consumo_vit, $distancia_vit, $combustivel_qtd_vit, $veiculo_vit, $combustivel_tipo_vit);
        $combustivel_qtd_vit = $litros_calc_vit;
        $fator_comb_vit      = $COMB_FATORES[norm_key($combustivel_tipo_vit)] ?? 0.0;
        $combustivel_emissao_vit = $fator_comb_vit * $litros_calc_vit;
    }
}


// ====== Fertilizantes ======
$fertilizante_fatores = [
  "Estrume bovino fresco" => 0.25,
  "Estrume de aves" => 0.35,
  "Esterco suíno líquido" => 0.04,
  "Composto orgânico" => 0.35,
  "Digestato de biogás" => 0.06,
  "Farinha de ossos / subprodutos" => 0.45,
  "Pellets de esterco/composto" => 0.35,
  "Ureia" => 4.55,
  "Nitrato de amónio" => 3.55,
  "Nitrato de cálcio" => 2.50,
  "Sulfato de amónio" => 2.35,
  "Nitrato de potássio" => 2.90,
  "Superfosfato simples (SSP)" => 0.45,
  "Superfosfato triplo (TSP)" => 0.85,
  "Fosfato monoamónico (MAP)" => 1.25,
  "Fosfato diamónico (DAP)" => 1.60,
  "Cloreto de potássio(KCL, MOP)" => 0.30,
  "Sulfato de potássio(K2SO4, SOP)" => 0.40,
  "Calcário agrícola" => 0.085
];
$fertilizante_emissao = 0.0;
$fertArr = isset($_POST['fertilizantes']) && is_array($_POST['fertilizantes']) ? $_POST['fertilizantes'] : [];
$fertArr = array_slice($fertArr, 0, 5);
foreach ($fertArr as $row) {
  $marca = isset($row['marca']) ? trim((string)$row['marca']) : '';
  $qtd   = num($row['qtd'] ?? null);
  if ($marca === '' || $qtd <= 0) continue;
  $fertilizante_emissao += ($fertilizante_fatores[$marca] ?? 0.0) * $qtd;
}

// ====== Herbicidas ======
$herbicida_fatores = [
  "Glifosato" => 20.3,
  "Flazasulfurão" => 6.7,
  "Oxifluorfeno" => 13.4,
  "Pendimetalina" => 13.5
];
$herbicida_emissao = 0.0;
$herbArr = isset($_POST['herbicidas']) && is_array($_POST['herbicidas']) ? $_POST['herbicidas'] : [];
$herbArr = array_slice($herbArr, 0, 5);
foreach ($herbArr as $row) {
  $marca = isset($row['marca']) ? trim((string)$row['marca']) : '';
  $qtd   = num($row['qtd'] ?? null);
  if ($marca === '' || $qtd <= 0) continue;
  $herbicida_emissao += ($herbicida_fatores[$marca] ?? 0.0) * $qtd;
}

// ====== Fitofármacos ======
$fitofarmacos_fatores = [
  "Enxofre" => 1.53, 
  "Cobre (óxidos, oxicloreto, hidróxido)" => 2.77, 
  "Folpete" => 12.5,
  "Fosetil de alumínio" => 10, 
  "Mandipropamida" => 15,
  "Oxatiapiprolina" => 22.5, 
  "Trifloxistrobina (estrobilurina)" => 17.5,
  "Tebuconazol (triazol)" => 14, 
  "Metrafenona" => 16,
  "Fluxapiroxade" => 18, 
  "Dimetomorfe" => 16,
  "Cimoxanil" => 16, 
  "Óleo parafínico" => 2.5,
  "Tau-fluvalinato" => 17.5, 
  "Cipermetrina" => 20,
  "Deltametrina (piretróide)" => 20.1, 
  "Piretróide (genérico)" => 20.5,
  "Trichoderma spp." => 1,
  "Acetato (E,Z)-7,9-dodecadien-1-ilo" => 7.5
];
$fitofarmaco_emissao = 0.0;
$fitoArr = isset($_POST['fitos']) && is_array($_POST['fitos']) ? $_POST['fitos'] : [];
$fitoArr = array_slice($fitoArr, 0, 5);
foreach ($fitoArr as $row) {
  $marca = isset($row['marca']) ? trim((string)$row['marca']) : '';
  $qtd   = num($row['qtd'] ?? null);
  if ($marca === '' || $qtd <= 0) continue;
  $fitofarmaco_emissao += ($fitofarmacos_fatores[$marca] ?? 0.0) * $qtd;
}


// --------------------- COLHEITA ---------------------
$tipo_transporte_colh   = strf('tipo_transporte_colh'); // Carro/Trator/Carrinha/Outro
$combustivel_tipo_colh  = strf('combustivel_tipo_colh');
$modo_consumo_colh      = strf('modo_consumo_colh');   // km | litros
$distancia_colh         = num($_POST['distancia_colh'] ?? null);
$combustivel_qtd_colh   = num($_POST['combustivel_qtd_colh'] ?? null);
$agua_colheita          = num($_POST['agua_colheita'] ?? null);
$uvas_colhidas          = num($_POST['uvas_colhidas'] ?? null); // t

$litros_calc_colh       = litros_from_modo($modo_consumo_colh, $distancia_colh, $combustivel_qtd_colh, $tipo_transporte_colh, $combustivel_tipo_colh);
$combustivel_qtd_colh   = $litros_calc_colh;
$fator_comb_colh        = $COMB_FATORES[norm_key($combustivel_tipo_colh)] ?? 0.0;   // <— usar normalização
$combustivel_emissao_colh = $fator_comb_colh * $litros_calc_colh;


// --------------------- TRANSPORTE ---------------------
$combustivel_emissao_trans = 0.0;
$combustivel_qtd_trans = num($_POST['combustivel_qtd_trans'] ?? null); // legado
$distancia_trans       = num($_POST['distancia_trans'] ?? null);       // legado

if (!empty($_POST['veiculos_trans']) && is_array($_POST['veiculos_trans'])) {
    $totTrans = process_vehicle_rows($_POST['veiculos_trans'], $eletricidade_origem, $COMB_FATORES,
                                     $EF_GRID_MISTA, $MAP_FATOR_ELEC);
    $distancia_trans            = $totTrans['km'];
    $combustivel_qtd_trans      = $totTrans['litros'];
    // nota: se houver veículos elétricos aqui, os kWh entram em $totTrans['emissao'] (via fator_ev)
    $combustivel_emissao_trans  = $totTrans['emissao'];
} else {
    // --- caminho legado (campos simples) ---
    $tipo_transporte_trans  = strf('tipo_transporte_trans');
    $combustivel_tipo_trans = strf('combustivel_tipo_trans');
    $modo_consumo_trans     = strf('modo_consumo_trans'); // km | litros

$litros_calc_trans         = litros_from_modo($modo_consumo_trans, $distancia_trans, $combustivel_qtd_trans, $tipo_transporte_trans, $combustivel_tipo_trans);
$combustivel_qtd_trans     = $litros_calc_trans;
$fator_comb_trans          = $COMB_FATORES[norm_key($combustivel_tipo_trans)] ?? 0.0; // <— usar normalização
$combustivel_emissao_trans = $fator_comb_trans * $litros_calc_trans;
}
// --------------------- UVA COMPRADA / VENDIDA ---------------------
$uvas_compradas_check    = isset($_POST['uvas_compradas_check']) ? (int)$_POST['uvas_compradas_check'] : 0;
$uvas_vendidas_check     = isset($_POST['uvas_vendidas_check'])  ? (int)$_POST['uvas_vendidas_check']  : 0;
$uvas_compradas_ton      = isset($_POST['uvas_compradas_toneladas']) ? num($_POST['uvas_compradas_toneladas']) : 0.0;
$uvas_vendidas_ton       = isset($_POST['uvas_vendidas_toneladas'])  ? num($_POST['uvas_vendidas_toneladas'])  : 0.0;

// --------------------- VINIFICAÇÃO ---------------------
// === Vinificação — novos 3 campos (kWh) ===
$vinif_kwh_rede   = post_float('vinif_kwh_rede',   0.0);
$vinif_kwh_solar  = post_float('vinif_kwh_solar',  0.0);
$vinif_kwh_eolica = post_float('vinif_kwh_eolica', 0.0);

// negativos não fazem sentido
$vinif_kwh_rede   = max(0.0, $vinif_kwh_rede);
$vinif_kwh_solar  = max(0.0, $vinif_kwh_solar);
$vinif_kwh_eolica = max(0.0, $vinif_kwh_eolica);

// Emissões de eletricidade na vinificação (soma ponderada)
$vinificacao_eletricidade_emissao =
    ($vinif_kwh_rede   * FE_ELEC_REDE)
  + ($vinif_kwh_solar  * FE_ELEC_SOLAR)
  + ($vinif_kwh_eolica * FE_ELEC_EOLICA);

// Total de kWh (se precisares para UI/BD)
$vinificacao_eletricidade_kwh = $vinif_kwh_rede + $vinif_kwh_solar + $vinif_kwh_eolica;

$agua_vinif = num($_POST['agua_vinif'] ?? null);

$gases_fatores = [
    "R-404A" => 3.92, 
    "R-134a" => 1.34,
    "R-449A" => 1.397,
    "R-407C" => 1.774, 
    "R-410A" => 2.088, 
    "R-422A" => 3.143, 
    "R-453A" => 1.765
];
$gases_emissao = 0.0;
$gasArr = isset($_POST['gases']) && is_array($_POST['gases']) ? $_POST['gases'] : [];
$gasArr = array_slice($gasArr, 0, 3);
foreach ($gasArr as $row) {
    $marca = isset($row['marca']) ? trim((string)$row['marca']) : '';
    $qtd   = num($row['qtd'] ?? null);
    if ($marca === '' || $qtd <= 0) continue;
    $gases_emissao += ($gases_fatores[$marca] ?? 0.0) * $qtd;
}

$produto_enologico_fatores = [
    "Leveduras" => 2.2, 
    "Enzimas" => 2.2, 
    "Nutrientes orgânicos" => 2.2,
    "Nutrientes minerais" => 0.733, 
    "Fosfato de diamónio" => 0.733, 
    "Bentonite" => 0.110,
    "Ácido tartárico" => 3.30, 
    "Ácido cítrico" => 3.3, 
    "Colas de origem animal" => 1.508, 
    "Diatomáceas" => 1.010,
    "Metabissulfito de potássio" => 1.470, 
    "Carbonato de cálcio" => 0.075, 
    "Cloreto de sódio" => 0.169,
    "Dióxido de Enxofre" => 0.440,
    "Taninos" => 2.2, 
    "Aparas" => 0.010,
     "Ácido lático" => 3.3,
    "Ácido málico" => 3.3, 
    "Goma Arábica" => 0.400
];
$produto_enologico_emissao = 0.0;
$prodEnolArr = isset($_POST['produtos_enologicos']) && is_array($_POST['produtos_enologicos']) ? $_POST['produtos_enologicos'] : [];
$prodEnolArr = array_slice($prodEnolArr, 0, 10);
foreach ($prodEnolArr as $row) {
    $marca = isset($row['marca']) ? trim((string)$row['marca']) : '';
    $qtd   = num($row['qtd'] ?? null);
    if ($marca === '' || $qtd <= 0) continue;
    $produto_enologico_emissao += ($produto_enologico_fatores[$marca] ?? 0.0) * $qtd;
}


$litros_vinificados = num($_POST['litros_vinificados'] ?? null);

// --------------------- ENGARRAFAMENTO ---------------------
// === Engarrafamento — novos 3 campos (kWh) ===
$engar_kwh_rede   = post_float('engar_kwh_rede',   0.0);
$engar_kwh_solar  = post_float('engar_kwh_solar',  0.0);
$engar_kwh_eolica = post_float('engar_kwh_eolica', 0.0);

$engar_kwh_rede   = max(0.0, $engar_kwh_rede);
$engar_kwh_solar  = max(0.0, $engar_kwh_solar);
$engar_kwh_eolica = max(0.0, $engar_kwh_eolica);

// Emissões de eletricidade no engarrafamento (soma ponderada)
$engarrafamento_eletricidade_emissao =
    ($engar_kwh_rede   * FE_ELEC_REDE)
  + ($engar_kwh_solar  * FE_ELEC_SOLAR)
  + ($engar_kwh_eolica * FE_ELEC_EOLICA);

// Total de kWh (se precisares para UI/BD)
$engarrafamento_eletricidade_kwh = $engar_kwh_rede + $engar_kwh_solar + $engar_kwh_eolica;


$produto_limpeza_fatores = [
    "Lauril sulfato de sódio, EDTA" =>0.473, 
    "Soda Cáustica" => 0.587, 
    "Hipoclorito de sódio" => 0.587,
    "C₆H₈O₇, citric acid, monohydrate" => 3.3
];
$produto_limpeza_emissao = 0.0;
$limpArr = isset($_POST['limpezas']) && is_array($_POST['limpezas']) ? $_POST['limpezas'] : [];
$limpArr = array_slice($limpArr, 0, 3);
foreach ($limpArr as $row) {
    $marca = isset($row['marca']) ? trim((string)$row['marca']) : '';
    $qtd   = num($row['qtd'] ?? null);
    if ($marca === '' || $qtd <= 0) continue;
    $produto_limpeza_emissao += ($produto_limpeza_fatores[$marca] ?? 0.0) * $qtd;
}
$agua_engarrafamento = num($_POST['agua_engarrafamento'] ?? null);
$volume_garrafa      = num($_POST['volume_garrafa'] ?? null);

// ===== ENGARRAFAMENTO — COMPONENTES (garrafa / rótulo / rolha)
// 1) Ligar/desligar a secção (checkbox do HTML)
$produz_componentes = checkbox01('produz_componentes_internamente'); // 0/1

// 2) Defaults
$material_emissao = 0.0;
$rotulo_emissao   = 0.0;
$rolha_emissao    = 0.0;

// 3) Escolhas do HTML (os value dos <select> DEVEM coincidir com as chaves dos arrays FE)
$material_sel = strf('material_garrafa'); // ex.: 'Vidro Branco' | 'Vidro Escuro' | 'Bag-in-box' | 'PET bottle'
$rotulo_sel   = strf('tipo_rotulo');      // ex.: 'Papel de impressão' | 'Papel/cartao' | 'filme plastico'
$rolha_sel    = strf('tipo_rolha');       // ex.: 'rolha cortiça natural $kwh_val' | 'rolha sintetica/técnica' | 'tampa de Alumínio'

// 4) Quantidades TOTAIS em kg(como no teu HTML)
$kg_garrafa = post_float('qtd_material_garrafa', 0.0); // kg
$kg_rotulo  = post_float('qtd_rotulo', 0.0);           // kg
$kg_rolha   = post_float('qtd_rolha', 0.0);            // kg

if ($produz_componentes === 1) {
    // 5) Procurar FE (kg CO2e por kg) — normalização de chaves p/ evitar desencontros de acentos/maiúsculas
    $EF_MATERIAL_GARRAFA_N = normalize_keys($EF_MATERIAL_GARRAFA ?? []);
    $EF_ROTULO_N           = normalize_keys($EF_ROTULO ?? []);
    $EF_ROLHA_N            = normalize_keys($EF_ROLHA ?? []);

    $ef_mat = $EF_MATERIAL_GARRAFA_N[norm_key($material_sel)] ?? 0.0; // kg/t
    $ef_rot = $EF_ROTULO_N[norm_key($rotulo_sel)] ?? 0.0;             // kg/t
    $ef_rol = $EF_ROLHA_N[norm_key($rolha_sel)] ?? 0.0;               // kg/t

    // 6) Emissões (kg CO2e) 
    if ($kg_garrafa > 0 && $ef_mat > 0) { $material_emissao = $kg_garrafa * $ef_mat; }
    if ($kg_rotulo  > 0 && $ef_rot > 0) { $rotulo_emissao   = $kg_rotulo  * $ef_rot; }
    if ($kg_rolha   > 0 && $ef_rol > 0) { $rolha_emissao    = $kg_rolha   * $ef_rol; }
}


// --------------------- DISTRIBUIÇÃO ---------------------
$incluir_distribuicao   = checkbox01('incluir_distribuicao'); // 0/1
$kwh_veic_dist = 0.0;
$quantidade_transportada = 0.0;
$eletricidade_armazem    = 0.0;
$veiculo_dist            = '';
$combustivel_tipo_dist   = '';  
$modo_consumo_dist       = '';
$distancia_dist          = 0.0;
$combustivel_qtd_dist    = 0.0;
$combustivel_emissao_dist = 0.0;

if ($incluir_distribuicao === 1) {
    $quantidade_transportada = num($_POST['quantidade_transportada'] ?? null);
    $eletricidade_armazem    = num($_POST['eletricidade_armazem'] ?? null);

    // Se o formulário enviou linhas repetíveis (veiculos_dist), processa-as.
    if (!empty($_POST['veiculos_dist']) && is_array($_POST['veiculos_dist'])) {
        // Para veículos elétricos na distribuição usamos por omissão o fator da "rede mista".
        // Se preferires outro fator, muda 'rede_mista' para a chave desejada do $MAP_FATOR_ELEC.
        $elec_origin_for_dist = 'rede_mista';

        $totDist = process_vehicle_rows($_POST['veiculos_dist'], $elec_origin_for_dist, $COMB_FATORES,
                                        $EF_GRID_MISTA, $MAP_FATOR_ELEC);

        $distancia_dist         = $totDist['km'];
        $combustivel_qtd_dist   = $totDist['litros'];
        $kwh_veic_dist            = $totDist['kwh'];
        $combustivel_emissao_dist = $totDist['emissao'];
    } else {
        // --- caminho legado (campos simples de distribuição) ---
        $veiculo_dist            = strf('veiculo_dist');
        $combustivel_tipo_dist   = strf('combustivel_tipo_dist');
        $modo_consumo_dist       = strf('modo_consumo_dist');  // km | litros
        $distancia_dist          = num($_POST['distancia_dist'] ?? null);
        $combustivel_qtd_dist    = num($_POST['combustivel_qtd_dist'] ?? null);

        $litros_calc_dist        = litros_from_modo($modo_consumo_dist, $distancia_dist, $combustivel_qtd_dist, $veiculo_dist, $combustivel_tipo_dist);
        $combustivel_qtd_dist    = $litros_calc_dist;
        $fator_comb_dist         = $COMB_FATORES[norm_key($combustivel_tipo_dist)] ?? 0.0;
        $combustivel_emissao_dist = $fator_comb_dist * $litros_calc_dist;
    }
}

//  número de garrafas distribuídas (se vier 0, deduzimos a partir do volume)
$garrafas_distribuidas = isset($_POST['garrafas_distribuidas'])
    ? max(0, (int)$_POST['garrafas_distribuidas'])
    : 0;

// já tens este no Engarrafamento; volta a usar aqui (L por garrafa):
$volume_garrafa_L = isset($_POST['volume_garrafa']) ? max(0.0, (float)$_POST['volume_garrafa']) : 0.0;

// se não veio nº de garrafas, deduz por volume/volume_garrafa
if ($incluir_distribuicao === 1 && $garrafas_distribuidas <= 0 && $quantidade_transportada > 0 && $volume_garrafa_L > 0) {
    $garrafas_distribuidas = (int) floor($quantidade_transportada / $volume_garrafa_L);
}


$km_total = floatval($km_total ?? 0);
$num_garrafas = floatval($num_garrafas ?? 0);


    
// --------------------- CÁLCULOS FINAIS ---------------------
// --- Fatores genéricos usados nos cálculos (podes mover para a secção dos EF) ---
$EF_AGUA      = 1.30; // kg CO2e por m³ de água (placeholder)


// --- Cálculo das emissões por etapa ---

// ===============================
// VOLUME TOTAL DE VINHO (V1)
// ===============================

$cf = 0.65; // default

if (isset($_POST['cf_uva_vinho']) && is_numeric($_POST['cf_uva_vinho']) && $_POST['cf_uva_vinho'] > 0) {
    $cf = floatval($_POST['cf_uva_vinho']);
}

$uvas_kg = (!empty($_POST['uvas_colhidas'])) ? floatval($_POST['uvas_colhidas']) * 1000.0 : 0.0;

$V_vinha = $uvas_kg * $cf;  // para viticultura + colheita-transporte
$V_vinif = (!empty($_POST['litros_vinificados']) && $_POST['litros_vinificados'] > 0)
    ? floatval($_POST['litros_vinificados'])
    : $V_vinha;              // fallback opcional
$V_dist = (!empty($_POST['quantidade_transportada']) && $_POST['quantidade_transportada'] > 0)
    ? floatval($_POST['quantidade_transportada'])
    : 0.0;






// Sequestro (t CO2e/ha·ano no input) -> kg


$sequestro_carbono = $sequestro_co * $area_vinha * 1000.0;

// VITICULTURA
$emissao_viticultura = ($V_vinha > 0)
    ? ((($combustivel_emissao_vit + ($agua_vit * $EF_AGUA) + $fertilizante_emissao + $herbicida_emissao + $fitofarmaco_emissao) - $sequestro_carbono) / $V_vinha)
    : 0.0;
$emissao_viticultura_bruta = $emissao_viticultura * $V_vinha;

// COLHEITA-TRANSPORTE
$emissao_colheita = $combustivel_emissao_colh + ($agua_colheita * $EF_AGUA);
$emissao_transporte = $combustivel_emissao_trans;
$emissao_colheita_transport = ($V_vinha > 0)
    ? (($emissao_colheita + $emissao_transporte) / $V_vinha)
    : 0.0;
$emissao_colheita_transport_bruta = $emissao_colheita_transport * $V_vinha;

// VINIFICAÇÃO
$emissao_vinificacao = ($V_vinif > 0)
    ? (($vinificacao_eletricidade_emissao + ($agua_vinif * $EF_AGUA) + $gases_emissao + $produto_enologico_emissao) / $V_vinif)
    : 0.0;
$emissao_vinificacao_bruta = $emissao_vinificacao * $V_vinif;

// ENGARRAFAMENTO
$emissao_engarrafamento = ($V_vinif > 0)
    ? (($engarrafamento_eletricidade_emissao + ($agua_engarrafamento * $EF_AGUA) + $produto_limpeza_emissao + $material_emissao + $rolha_emissao + $rotulo_emissao) / $V_vinif)
    : 0.0;
$emissao_engarrafamento_bruta = $emissao_engarrafamento * $V_vinif;
// DISTRIBUIÇÃO 

$emissao_distribuicao = ($incluir_distribuicao === 1 && $V_dist > 0)
    ? (($combustivel_emissao_dist + $eletricidade_armazem * $EF_GRID_MISTA) / $V_dist)
    : 0.0;

$emissao_distribuicao_bruta = ($incluir_distribuicao === 1 && $V_dist > 0)
    ? ($emissao_distribuicao * $V_dist)
    : 0.0;

// Somatório total de kWh na etapa de distribuição: veículos EV + armazém
$kwh_total_dist = ($incluir_distribuicao === 1) ? ($kwh_veic_dist + $eletricidade_armazem) : 0.0;






// Totais por etapa já calculados acima:
$emissao_total_bruta =
    $emissao_viticultura
  + $emissao_colheita_transport
  + $emissao_vinificacao
  + $emissao_engarrafamento
  + $emissao_distribuicao;

$emissao_total=$emissao_viticultura_bruta
    +$emissao_colheita_transport_bruta
    +$emissao_vinificacao_bruta
    +$emissao_engarrafamento_bruta
    +$emissao_distribuicao_bruta;

// Emissões por garrafa (se tivermos litros vinificados e volume de garrafa)
$emissao_garrafa = 0.0;
if ($volume_garrafa > 0 && $litros_vinificados > 0) {
    $emissao_garrafa   = $emissao_total_bruta * $volume_garrafa;    // kg CO2e por garrafa
}


$aditivos = 0.0;
$gas_inerte = 0.0;



// --- Inserção na base de dados (modelo wine_making_db3) ---

/**
 * Helpers para criar/obter company, user e yearbook
 * (por agora com dados “demo”; mais tarde podes vir estes campos do formulário)
 */
function id_or_create_company_v3(mysqli $conn, string $name, string $nif = null, string $country = null): int {
    // procura por nome + nif
    $sql = "SELECT id FROM company WHERE name = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($id);
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$id;
    }
    $stmt->close();

    $sql = "INSERT INTO company (name, nif, country) VALUES (?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $name, $nif, $country);
    $stmt->execute();
    $newId = (int)$conn->insert_id;
    $stmt->close();
    return $newId;
}

function id_or_create_user_v3(mysqli $conn, int $company_id, string $name, string $email): int {
    // tabela chama-se `user` no novo esquema
    $sql = "SELECT id FROM `user` WHERE company_id = ? AND email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $company_id, $email);
    $stmt->execute();
    $stmt->bind_result($id);
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$id;
    }
    $stmt->close();

    $password_hash = password_hash('demo123', PASSWORD_DEFAULT); // placeholder
    $role = 'admin';

    $sql = "INSERT INTO `user` (company_id, name, email, password_hash, role)
            VALUES (?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issss', $company_id, $name, $email, $password_hash, $role);
    $stmt->execute();
    $newId = (int)$conn->insert_id;
    $stmt->close();
    return $newId;
}

function id_or_create_yearbook_v3(mysqli $conn, int $company_id, int $user_id, int $year): int {
    $sql = "SELECT id FROM yearbook WHERE company_id = ? AND year = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $company_id, $year);
    $stmt->execute();
    $stmt->bind_result($id);
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$id;
    }
    $stmt->close();

    $total_volume_l = 0.0; // podes pôr $litros_vinificados aqui se quiseres
    $sql = "INSERT INTO yearbook (company_id, user_id, year, total_volume_l)
            VALUES (?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiid', $company_id, $user_id, $year, $total_volume_l);
    $stmt->execute();
    $newId = (int)$conn->insert_id;
    $stmt->close();
    return $newId;
}

/**
 * Helper para obter/garantir stage_year (associação yearbook + stage)
 */
function get_stage_year_id(mysqli $conn, int $yearbook_id, string $stage_code): int {
    // procura stage.id
    $sql = "SELECT id FROM stage WHERE code = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $stage_code);
    $stmt->execute();
    $stmt->bind_result($stage_id);
    if (!$stmt->fetch()) {
        $stmt->close();
        throw new RuntimeException("Stage com code={$stage_code} não existe na BD.");
    }
    $stmt->close();

    // procura associação
    $sql = "SELECT id FROM stage_year WHERE yearbook_id = ? AND stage_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $yearbook_id, $stage_id);
    $stmt->execute();
    $stmt->bind_result($sy_id);
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$sy_id;
    }
    $stmt->close();

    // cria associação
    $sql = "INSERT INTO stage_year (yearbook_id, stage_id) VALUES (?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $yearbook_id, $stage_id);
    $stmt->execute();
    $newId = (int)$conn->insert_id;
    $stmt->close();
    return $newId;
}

/* =========================
 * 1) company / user / yearbook
 * ========================= */

$report_year = (int)date('Y');

// por agora hard-code; depois podemos pôr estes campos logo no formulário
$company_name = 'Quinta Demo';
$company_nif  = 'PT999999990';
$company_ctry = 'Portugal';

$user_name  = 'Utilizador Demo';
$user_email = 'demo@example.com';

$company_id = id_or_create_company_v3($conn, $company_name, $company_nif, $company_ctry);
$user_id    = id_or_create_user_v3($conn, $company_id, $user_name, $user_email);
$yearbook_id = id_or_create_yearbook_v3($conn, $company_id, $user_id, $report_year);

/* =========================
 * 2) Viticultura
 * ========================= */

$sy_vit = get_stage_year_id($conn, $yearbook_id, 'VITICULTURA');

$sql = "INSERT INTO viticultura (stage_year_id, agua_m3, area_vinha_ha, sequestro_nivel, sequestro_valor)
        VALUES (?,?,?,?,?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
    'iddsd',
    $sy_vit,
    $agua_vit,
    $area_vinha,
    $sequestro_nivel,
    $sequestro_co       // valor usado nos cálculos (t CO2e/ha·ano ou valor médio)
);
$stmt->execute();
$viticultura_id = (int)$conn->insert_id;
$stmt->close();

/* Veículos viticultura */
if (!empty($_POST['veiculos_vit']) && is_array($_POST['veiculos_vit'])) {
    $sql = "INSERT INTO viticultura_vehicle
              (viticultura_id, tipo_veiculo, combustivel, modo, distancia_km, litros, kwh)
            VALUES (?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['veiculos_vit'] as $row) {
        $tipo   = (string)($row['veiculo']      ?? '');
        $comb   = (string)($row['combustivel']  ?? '');
        $modo   = (string)($row['modo']         ?? '');
        $dist   = num($row['distancia'] ?? null);
        $litros = num($row['litros']    ?? null);
        $kwh    = num($row['kwh']       ?? null);
        if ($tipo === '' && $comb === '' && $dist <= 0 && $litros <= 0 && $kwh <= 0) continue;
        $stmt->bind_param('isssddd', $viticultura_id, $tipo, $comb, $modo, $dist, $litros, $kwh);
        $stmt->execute();
    }
    $stmt->close();
}

/* Fertilizantes */
if (!empty($_POST['fertilizantes']) && is_array($_POST['fertilizantes'])) {
    $sql = "INSERT INTO viticultura_fertilizante (viticultura_id, marca, quantidade_kg)
            VALUES (?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['fertilizantes'] as $row) {
        $marca = trim((string)($row['marca'] ?? ''));
        $qtd   = num($row['qtd'] ?? null);
        if ($marca === '' || $qtd <= 0) continue;
        $stmt->bind_param('isd', $viticultura_id, $marca, $qtd);
        $stmt->execute();
    }
    $stmt->close();
}

/* Herbicidas */
if (!empty($_POST['herbicidas']) && is_array($_POST['herbicidas'])) {
    $sql = "INSERT INTO viticultura_herbicida (viticultura_id, marca, quantidade_kg)
            VALUES (?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['herbicidas'] as $row) {
        $marca = trim((string)($row['marca'] ?? ''));
        $qtd   = num($row['qtd'] ?? null);
        if ($marca === '' || $qtd <= 0) continue;
        $stmt->bind_param('isd', $viticultura_id, $marca, $qtd);
        $stmt->execute();
    }
    $stmt->close();
}

/* Fitofármacos */
if (!empty($_POST['fitos']) && is_array($_POST['fitos'])) {
    $sql = "INSERT INTO viticultura_fitofarmaco (viticultura_id, marca, quantidade_kg)
            VALUES (?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['fitos'] as $row) {
        $marca = trim((string)($row['marca'] ?? ''));
        $qtd   = num($row['qtd'] ?? null);
        if ($marca === '' || $qtd <= 0) continue;
        $stmt->bind_param('isd', $viticultura_id, $marca, $qtd);
        $stmt->execute();
    }
    $stmt->close();
}

/* =========================
 * 3) Colheita / Transporte de uva
 * ========================= */

$sy_col = get_stage_year_id($conn, $yearbook_id, 'COLHEITA');

$sql = "INSERT INTO colheita (stage_year_id, uvas_colhidas_t, agua_m3)
        VALUES (?,?,?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('idd', $sy_col, $uvas_colhidas, $agua_colheita);
$stmt->execute();
$colheita_id = (int)$conn->insert_id;
$stmt->close();

/* Veículos da colheita (veiculos_trans) */
if (!empty($_POST['veiculos_trans']) && is_array($_POST['veiculos_trans'])) {
    $sql = "INSERT INTO colheita_vehicle
              (colheita_id, tipo_veiculo, combustivel, modo, distancia_km, litros, kwh)
            VALUES (?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['veiculos_trans'] as $row) {
        $tipo   = (string)($row['veiculo']      ?? '');
        $comb   = (string)($row['combustivel']  ?? '');
        $modo   = (string)($row['modo']         ?? '');
        $dist   = num($row['distancia'] ?? null);
        $litros = num($row['litros']    ?? null);
        $kwh    = num($row['kwh']       ?? null);
        if ($tipo === '' && $comb === '' && $dist <= 0 && $litros <= 0 && $kwh <= 0) continue;
        $stmt->bind_param('isssddd', $colheita_id, $tipo, $comb, $modo, $dist, $litros, $kwh);
        $stmt->execute();
    }
    $stmt->close();
}

/* =========================
 * 4) Vinificação
 * ========================= */

$sy_vin = get_stage_year_id($conn, $yearbook_id, 'VINIFICACAO');

$comprou_uvas = $uvas_compradas_check ? 1 : 0;
$uvas_t       = $uvas_compradas_check ? $uvas_compradas_ton : 0.0;

// origem da eletricidade (simplificação)
$vinif_auto_kwh = $vinif_kwh_solar + $vinif_kwh_eolica;
$vinif_rede_kwh = $vinif_kwh_rede;
$vinif_total_kwh = $vinificacao_eletricidade_kwh;
$vinif_origem = ($vinif_auto_kwh > 0 && $vinif_rede_kwh == 0) ? 'Autoconsumo' : 'nao_renovavel';

$sql = "INSERT INTO vinificacao
          (stage_year_id, litros_vinificados, comprou_uvas, uva_toneladas,
           eletricidade_origem, eletricidade_total_kwh, eletricidade_auto_kwh,
           eletricidade_rede_kwh, agua_m3)
        VALUES (?,?,?,?,?,?,?,?,?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
    'idisddddd',
    $sy_vin,             // i
    $litros_vinificados, // d
    $comprou_uvas,       // i
    $uvas_t,             // d
    $vinif_origem,       // s
    $vinif_total_kwh,    // d
    $vinif_auto_kwh,     // d
    $vinif_rede_kwh,     // d
    $agua_vinif          // d
);
$stmt->execute();
$vinificacao_id = (int)$conn->insert_id;
$stmt->close();

/* Gases refrigerantes */
if (!empty($_POST['gases']) && is_array($_POST['gases'])) {
    $sql = "INSERT INTO vinificacao_refrigerante (vinificacao_id, marca, quantidade_kg)
            VALUES (?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['gases'] as $row) {
        $marca = trim((string)($row['marca'] ?? ''));
        $qtd   = num($row['qtd'] ?? null);
        if ($marca === '' || $qtd <= 0) continue;
        $stmt->bind_param('isd', $vinificacao_id, $marca, $qtd);
        $stmt->execute();
    }
    $stmt->close();
}

/* Produtos enológicos */
if (!empty($_POST['produtos_enologicos']) && is_array($_POST['produtos_enologicos'])) {
    $sql = "INSERT INTO vinificacao_enologico (vinificacao_id, marca, quantidade_kg)
            VALUES (?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['produtos_enologicos'] as $row) {
        $marca = trim((string)($row['marca'] ?? ''));
        $qtd   = num($row['qtd'] ?? null);
        if ($marca === '' || $qtd <= 0) continue;
        $stmt->bind_param('isd', $vinificacao_id, $marca, $qtd);
        $stmt->execute();
    }
    $stmt->close();
}

/* =========================
 * 5) Engarrafamento
 * ========================= */

$sy_eng = get_stage_year_id($conn, $yearbook_id, 'ENGARRAFAMENTO');

$engar_auto_kwh  = $engar_kwh_solar + $engar_kwh_eolica;
$engar_rede_kwh  = $engar_kwh_rede;
$engar_total_kwh = $engarrafamento_eletricidade_kwh;
$engar_origem    = ($engar_auto_kwh > 0 && $engar_rede_kwh == 0) ? 'autoconsumo' : 'nao_renovavel';
$kg_garrafa_g = $kg_garrafa * 1000.0;
$kg_rotulo_g  = $kg_rotulo * 1000.0;
$kg_rolha_g   = $kg_rolha  * 1000.0;

$sql = "INSERT INTO engarrafamento
          (stage_year_id, eletricidade_origem, eletricidade_kwh,
           agua_m3, volume_garrafa_l, produz_componentes_internos,
           material_garrafa, qtd_material_garrafa_g,
           tipo_rotulo, qtd_rotulo_g,
           tipo_rolha, qtd_rolha_g)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
    'isdddisdsdsd',
    $sy_eng,
    $engar_origem,
    $engar_total_kwh,
    $agua_engarrafamento,
    $volume_garrafa,
    $produz_componentes,
    $material_sel,
    $kg_garrafa_g,   
    $rotulo_sel,
    $kg_rotulo_g,   
    $rolha_sel,
    $kg_rolha_g     
);

$stmt->execute();
$engarrafamento_id = (int)$conn->insert_id;
$stmt->close();

/* Produtos de limpeza */
if (!empty($_POST['limpezas']) && is_array($_POST['limpezas'])) {
    $sql = "INSERT INTO engarrafamento_limpeza (engarrafamento_id, marca, quantidade_kg)
            VALUES (?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['limpezas'] as $row) {
        $marca = trim((string)($row['marca'] ?? ''));
        $qtd   = num($row['qtd'] ?? null);
        if ($marca === '' || $qtd <= 0) continue;
        $stmt->bind_param('isd', $engarrafamento_id, $marca, $qtd);
        $stmt->execute();
    }
    $stmt->close();
}

/* =========================
 * 6) Distribuição
 * ========================= */

$sy_dis = get_stage_year_id($conn, $yearbook_id, 'DISTRIBUICAO');

$sql = "INSERT INTO distribuicao
          (stage_year_id, incluir_distribuicao,
           quantidade_transportada_l, eletricidade_armazem_kwh)
        VALUES (?,?,?,?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
    'iidd',
    $sy_dis,
    $incluir_distribuicao,
    $quantidade_transportada,
    $eletricidade_armazem
);
$stmt->execute();
$distribuicao_id = (int)$conn->insert_id;
$stmt->close();

/* Veículos de distribuição */
if (!empty($_POST['veiculos_dist']) && is_array($_POST['veiculos_dist'])) {
    $sql = "INSERT INTO distribuicao_vehicle
              (distribuicao_id, tipo_veiculo, combustivel, modo, distancia_km, litros, kwh)
            VALUES (?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    foreach ($_POST['veiculos_dist'] as $row) {
        $tipo   = (string)($row['veiculo']      ?? '');
        $comb   = (string)($row['combustivel']  ?? '');
        $modo   = (string)($row['modo']         ?? '');
        $dist   = num($row['distancia'] ?? null);
        $litros = num($row['litros']    ?? null);
        $kwh    = num($row['kwh']       ?? null);
        if ($tipo === '' && $comb === '' && $dist <= 0 && $litros <= 0 && $kwh <= 0) continue;
        $stmt->bind_param('isssddd', $distribuicao_id, $tipo, $comb, $modo, $dist, $litros, $kwh);
        $stmt->execute();
    }
    $stmt->close();
}

// --- fim da inserção na BD (depois disto continuam os echo do resultado/grafico ---







// --- Resultado ---
$fmt = function ($x) { return number_format((float)$x, 4, ',', ' '); }; // ex.: 1 234,56

echo '<h2>✅ Dados submetidos com sucesso!</h2>';
echo '<h3>🌍 Pegada de Carbono Total : <strong>' . $fmt($emissao_total) . ' kg CO₂e</strong></h3>';
echo '<h3>💨 Emissões por litro : <strong>' . $fmt($emissao_total_bruta) . ' kg/L</strong></h3>';

echo '<hr style="border:0;border-top:1px solid rgba(255, 255, 255, 0.7);margin:10px 0;">';

echo '<h3>🍃 Emissões da Viticultura: <strong>' . $fmt($emissao_viticultura) . ' kg/L</strong></h3>';
echo '<h3>🍇-🚚  Emissões da Colheita-transport: <strong>' . $fmt($emissao_colheita_transport) . ' kg/L</strong></h3>';
echo '<h3>🍷 Emissões da Vinificação: <strong>' . $fmt($emissao_vinificacao) . ' kg/L</strong></h3>';
echo '<h3>🍾 Emissões do Engarrafamento: <strong>' . $fmt($emissao_engarrafamento) . ' kg/L</strong></h3>';
echo '<h3>📦 Emissões da Distribuição: <strong>' . $fmt($emissao_distribuicao) . ' kg/L</strong></h3>';
    if ($volume_garrafa > 0 && $litros_vinificados > 0) {
        echo "<h3>🧪 Emissões por garrafa de {$fmt($volume_garrafa)} L: <strong>{$fmt($emissao_garrafa)} kg</strong></h3>";
    }

    echo "<div style='max-width:520px;margin:20px 0;'>
            <canvas id='graficoEtapas' width='520' height='520'></canvas>
          </div>";

    $dadosGrafico = [
        round((float)$emissao_viticultura,    2),
        round((float)$emissao_colheita_transport, 2),
        round((float)$emissao_vinificacao,    2),
        round((float)$emissao_engarrafamento, 2),
        round((float)$emissao_distribuicao,   2),
    ];
echo "
<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
<script src='https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2'></script>
<script>
  (function() {
    const dados  = " . json_encode($dadosGrafico) . ";
    const labels = ['Viticultura','Colheita-Transporte','Vinificação','Engarrafamento','Distribuição'];

    Chart.register(ChartDataLabels);
    const total = dados.reduce((a,b) => a + b, 0);

    const ctx = document.getElementById('graficoEtapas').getContext('2d');
    new Chart(ctx, {
      type: 'pie',
      data: {
        labels,
        datasets: [{
          label: 'Emissões por Etapa (kg CO₂eq/L)',
          data: dados,
          backgroundColor: [
            'rgba(250,234,13,0.92)',
            'rgba(139,195,74,0.6)',
            'rgba(255,152,0,0.6)',
            'rgba(33,150,243,0.6)',
            'rgba(156,39,176,0.6)'
          ],
          borderColor: [
            'rgba(250,234,13,1)',
            'rgba(139,195,74,1)',
            'rgba(255,152,0,1)',
            'rgba(33,150,243,1)',
            'rgba(156,39,176,1)'
          ],
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom' },
          title: { display: true, text: 'Distribuição da Pegada de Carbono por Etapa' },
          tooltip: {
            callbacks: {
              label: function(context) {
                const v = context.parsed;
                const p = total > 0 ? (v/total*100) : 0;
                const valor = v.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                return ' ' + context.label + ': ' + valor + ' kg CO₂eq (' + p.toFixed(1) + '%)';
              }
            }
          },
          datalabels: {
            formatter: function(value, ctx) {
              const tot = ctx.chart.data.datasets[0].data.reduce((a,b) => a + b, 0);
              if (tot <= 0) return '';
              const p = value / tot * 100;
              if (p < 2) return '';
              return p.toFixed(1) + '%';
            },
            anchor: 'center',
            align: 'center',
            color: '#111',
            font: { weight: '700', size: 12 }
          }
        }
      }
    });
  })();
</script>
";



// ---------- Pré-cálculos para a tabela de detalhes ----------
$agua_vit_em       = $agua_vit * $EF_AGUA;
$agua_colh_em      = $agua_colheita * $EF_AGUA;
$vinif_agua_em     = $agua_vinif * $EF_AGUA;
$engar_agua_em     = $agua_engarrafamento * $EF_AGUA;
$dist_elec_em      = $eletricidade_armazem * $EF_GRID_MISTA;

// usa o mesmo formatador do topo
$F = $fmt;

// VITICULTURA
$f_emissao_viticultura   = $F($emissao_viticultura);
$f_emissao_viticultura_bruta = $F($emissao_viticultura_bruta);
$f_comb_em_vit           = $F($combustivel_emissao_vit);
$f_agua_vit_em           = $F($agua_vit_em);
$f_fertilizante_emissao  = $F($fertilizante_emissao);
$f_herbicida_emissao     = $F($herbicida_emissao);
$f_fitofarmaco_emissao   = $F($fitofarmaco_emissao);

// COLHEITA-TRANSPORTE
$f_emissao_colheita_transport = $F($emissao_colheita_transport);
$f_emissao_colheita_transport_bruta = $F($emissao_colheita_transport_bruta);
$f_comb_em_colh          = $F($combustivel_emissao_colh);
$f_agua_colh_em          = $F($agua_colh_em);
$f_comb_em_trans         = $F($combustivel_emissao_trans);

// VINIFICAÇÃO
$f_emissao_vinificacao   = $F($emissao_vinificacao);
$f_emissao_vinificacao_bruta = $F($emissao_vinificacao_bruta);
$f_emissao_elec_vinif = $F($vinificacao_eletricidade_emissao);
$f_vinif_agua_em         = $F($vinif_agua_em);
$f_gases_emissao         = $F($gases_emissao);
$f_prod_enol_emissao     = $F($produto_enologico_emissao);


// ENGARRAFAMENTO
$f_emissao_engar         = $F($emissao_engarrafamento);
$f_emissao_engar_bruta   = $F($emissao_engarrafamento_bruta);
$f_emissao_elec_engar = $F($engarrafamento_eletricidade_emissao);
$f_eng_agua_em           = $F($engar_agua_em);
$f_prod_limp_emissao     = $F($produto_limpeza_emissao);
$material_emissao_garrafa = $F($material_emissao);
$rolha_emissao_garrafa    = $F($rolha_emissao);
$rotulo_emissao_garrafa   = $F($rotulo_emissao);




// DISTRIBUIÇÃO
$f_emissao_distribuicao  = $F($emissao_distribuicao);
$f_emissao_distribuicao_bruta = $F($emissao_distribuicao_bruta);
$f_comb_em_dist          = $F($combustivel_emissao_dist);
$f_dist_elec_em          = $F($dist_elec_em);
$f_garrafas_distribuidas  = number_format((float)$garrafas_distribuidas, 0, ',', ' ');

// eletricidade
$f_vinif_kwh_rede   = $F($vinif_kwh_rede);
$f_vinif_kwh_solar  = $F($vinif_kwh_solar);
$f_vinif_kwh_eolica = $F($vinif_kwh_eolica);

$f_engar_kwh_rede   = $F($engar_kwh_rede);
$f_engar_kwh_solar  = $F($engar_kwh_solar);
$f_engar_kwh_eolica = $F($engar_kwh_eolica);

//outpust da eletricidade
$vinif_elec_rede_emissao   = $vinif_kwh_rede   * FE_ELEC_REDE;
$vinif_elec_solar_emissao  = $vinif_kwh_solar  * FE_ELEC_SOLAR;
$vinif_elec_eolica_emissao = $vinif_kwh_eolica * FE_ELEC_EOLICA;
$f_vinif_elec_rede_emissao   = $F($vinif_elec_rede_emissao);
$f_vinif_elec_solar_emissao  = $F($vinif_elec_solar_emissao);
$f_vinif_elec_eolica_emissao = $F($vinif_elec_eolica_emissao);

$engar_elec_rede_emissao   = $engar_kwh_rede   * FE_ELEC_REDE;
$engar_elec_solar_emissao  = $engar_kwh_solar  * FE_ELEC_SOLAR;
$engar_elec_eolica_emissao = $engar_kwh_eolica * FE_ELEC_EOLICA;
$f_engar_elec_rede_emissao   = $F($engar_elec_rede_emissao);
$f_engar_elec_solar_emissao  = $F($engar_elec_solar_emissao);
$f_engar_elec_eolica_emissao = $F($engar_elec_eolica_emissao);



// TOTAIS
$f_total_bruta           = $F($emissao_total_bruta);
$f_total_liquida         = $F($emissao_total);
$f_sequestro             = $F($sequestro_carbono);


// OPCIONAIS (por garrafa)
$f_volume_garrafa        = $F($volume_garrafa);
$f_emissao_garrafa       = ($volume_garrafa > 0 && $litros_vinificados > 0) ? $F($emissao_garrafa) : '';
$f_comb_colh_trans = $F($combustivel_emissao_colh + $combustivel_emissao_trans);

// =====================
// Botão + Tabela extra
// =====================
echo <<<HTML
<button id="btnDetalhes" style="margin-top:10px;">Mostrar detalhes</button>

<div id="wrapDetalhes" style="display:none; margin-top:14px;">
  <table style="width:100%; border-collapse:collapse; background:#fff;">
    <thead>
      <tr style="background:#eee;">
        <th style="text-align:left; padding:8px; border:1px solid #ddd;">Etapa  </th>
        <th style="text-align:right; padding:8px; border:1px solid #ddd;">Emissões (kg CO₂e)</th>
      </tr>
    </thead>
    <tbody>
      <tr><td><strong>Viticultura Bruta</strong></td><td style="text-align:right;"><strong>{$f_emissao_viticultura_bruta}</strong></td></tr>
      <tr><td><strong>Viticultura por litro</strong></td><td style="text-align:right;"><strong>{$f_emissao_viticultura}</strong></td></tr>
      <tr><td>Veículos (combustível/eletricidade)</td><td style="text-align:right;">{$f_comb_em_vit}</td></tr>
      <tr><td>Água</td><td style="text-align:right;">{$f_agua_vit_em}</td></tr>
      <tr><td>Fertilizantes</td><td style="text-align:right;">{$f_fertilizante_emissao}</td></tr>
      <tr><td>Herbicidas</td><td style="text-align:right;">{$f_herbicida_emissao}</td></tr>
      <tr><td>Fitofármacos</td><td style="text-align:right;">{$f_fitofarmaco_emissao}</td></tr>
      <tr style="background:#f9f9f9;"><td>Sequestro de carbono </td><td style="text-align:right;">- {$f_sequestro}</td></tr>

      <tr><td><strong>Colheita-Transporte Bruta</strong></td><td style="text-align:right;"><strong>{$f_emissao_colheita_transport_bruta}</strong></td></tr>
      <tr>
        <td><strong>Colheita e Transporte por litro</strong></td>
        <td style="text-align:right;"><strong>{$f_emissao_colheita_transport}</strong></td>
      </tr>
      <tr>
        <td>Combustível / quilometragem</td>
        <td style="text-align:right;">{$f_comb_colh_trans}</td></tr>
      <tr>
        <td>Água</td>
        <td style="text-align:right;">{$f_agua_colh_em}</td>
      </tr>

    
      <tr><td><strong>Vinificação Bruta</strong></td><td style="text-align:right;"><strong>{$f_emissao_vinificacao_bruta}</strong></td></tr>
      <tr><td><strong>Vinificação por litro</strong></td><td style="text-align:right;"><strong>{$f_emissao_vinificacao}</strong></td></tr>
      <tr><td>Eletricidade</td><td style="text-align:right;">{$f_emissao_elec_vinif}</td></tr>
      <tr><td>Água</td><td style="text-align:right;">{$f_vinif_agua_em}</td></tr>
      <tr><td>Gases refrigerantes</td><td style="text-align:right;">{$f_gases_emissao}</td></tr>
      <tr><td>Produtos enológicos</td><td style="text-align:right;">{$f_prod_enol_emissao}</td></tr>
  

      <tr><td><strong>Engarrafamento Bruta</strong></td><td style="text-align:right;"><strong>{$f_emissao_engar_bruta}</strong></td></tr>  
      <tr><td><strong>Engarrafamento por litro</strong></td><td style="text-align:right;"><strong>{$f_emissao_engar}</strong></td></tr>
      <tr><td>Eletricidade</td><td style="text-align:right;">{$f_emissao_elec_engar}</td></tr>
      <tr><td>Água</td><td style="text-align:right;">{$f_eng_agua_em}</td></tr>
      <tr><td>Produtos de limpeza</td><td style="text-align:right;">{$f_prod_limp_emissao}</td></tr>
      <tr><td> Material da garrafa</td><td style="text-align:right;">{$material_emissao_garrafa}</td></tr>
      <tr><td> Rolha</td><td style="text-align:right;">{$rolha_emissao_garrafa}</td></tr>
      <tr><td> Rótulo</td><td style="text-align:right;">{$rotulo_emissao_garrafa}</td></tr>

      <tr><td><strong>Distribuição Bruta</strong></td><td style="text-align:right;"><strong>{$f_emissao_distribuicao_bruta}</strong></td></tr>  
      <tr><td><strong>Distribuição</strong></td><td style="text-align:right;"><strong>{$f_emissao_distribuicao}</strong></td></tr>
      <tr><td>Combustível/quilometragem</td><td style="text-align:right;">{$f_comb_em_dist}</td></tr>
      <tr><td>Eletricidade em armazém</td><td style="text-align:right;">{$f_dist_elec_em}</td></tr>

      <tr><td>N.º garrafas distribuídas</td><td style="text-align:right;">{$f_garrafas_distribuidas}</td></tr>
  
      <!-- em Vinificação, logo abaixo de “Eletricidade” -->
       <tr><td><strong> resultados da eletricidade</strong></td><td style="text-align:right;"></td></tr>
        <tr>
            <td>Eletricidade rede vinificaçao</td>
            <td style="text-align:right;">{$f_vinif_elec_rede_emissao}</td>
        </tr>
        <tr>
            <td>Eletricidade  solar vinificaçao </td>
            <td style="text-align:right;">{$f_vinif_elec_solar_emissao}</td>
        </tr>
        <tr>
            <td>Eletricidade  eólica vinificaçao </td>
            <td style="text-align:right;">{$f_vinif_elec_eolica_emissao}</td>
        </tr>


      <!-- em Engarrafamento, logo abaixo de “Eletricidade” -->
        <tr>
            <td>Eletricidade rede Engarrafamento </td>
            <td style="text-align:right;">{$f_engar_elec_rede_emissao}</td>
        </tr>
        <tr>
            <td>Eletricidade  solar Engarrafamento</td>
            <td style="text-align:right;">{$f_engar_elec_solar_emissao}</td>
        </tr>
        <tr>
            <td>Eletricidade  eólica Engarrafamento </td>
            <td style="text-align:right;">{$f_engar_elec_eolica_emissao}</td>
        </tr>


      <tr style="background:#f0f0f0;"><td><strong>Total Bruto </strong></td><td style="text-align:right;"><strong>{$f_total_liquida}</strong></td></tr>  
      <tr style="background:#f9f9f9;"><td><strong>Total por litro   </strong></td><td style="text-align:right;"><strong>{$f_total_bruta}</strong></td></tr>
      
      
HTML;

if ($volume_garrafa > 0 && $litros_vinificados > 0) {
    echo "<tr><td>Emissões por garrafa ({$f_volume_garrafa} L)</td><td style='text-align:right;'>{$f_emissao_garrafa}</td></tr>";
}

echo <<<HTML
    </tbody>
  </table>
</div>

<script>
  (function(){
    const btn = document.getElementById('btnDetalhes');
    const box = document.getElementById('wrapDetalhes');
    if (!btn || !box) return;
    btn.addEventListener('click', function(){
      const isHidden = (box.style.display === 'none' || box.style.display === '');
      box.style.display = isHidden ? 'block' : 'none';
      btn.textContent = isHidden ? 'Ocultar detalhes' : 'Mostrar detalhes';
    });
  })();
</script>
HTML;

if ($conn) { $conn->close(); }

