<?php
// form-agent.php — Server-side AI Form Filling Agent
// Extension sends form fields + customer data; server returns fill mapping.
// All intelligence (rules, AI, caching) lives here — update anytime without Chrome Store review.

error_reporting(0); // suppress warnings — output must be clean JSON
set_error_handler(function($errno, $errstr) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => "PHP error: $errstr"]);
    exit();
});

// Load .env (provides OPENAI_API_KEY)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        putenv("$k=$v");
    }
}

require_once __DIR__ . '/openai-cost-helper.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// ── FILE-BASED MAPPING CACHE ──────────────────────────────────────────────────
// Stored in /tmp/form_cache/ — no Firestore rules needed, instant reads
define('CACHE_DIR', __DIR__ . '/cache/');

function cacheGet(string $sig): ?array {
    $file = CACHE_DIR . preg_replace('/[^a-z0-9]/','', $sig) . '.json';
    if (!file_exists($file)) return null;
    if (time() - filemtime($file) > 30 * 24 * 3600) { unlink($file); return null; } // 30-day TTL
    $data = json_decode(file_get_contents($file), true);
    return (is_array($data) && !empty($data)) ? $data : null;
}

function cacheSave(string $sig, array $mapping): void {
    if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
    $file = CACHE_DIR . preg_replace('/[^a-z0-9]/','', $sig) . '.json';
    // JSON_FORCE_OBJECT ensures numeric-string keys like "0","1" become {"0":...} not [...]
    file_put_contents($file, json_encode($mapping, JSON_FORCE_OBJECT), LOCK_EX);
}

// ── PARSE INPUT ───────────────────────────────────────────────────────────────
$input        = json_decode(file_get_contents('php://input'), true);
$fields       = $input['fields']        ?? [];
$customerData = $input['customerData']  ?? [];
$formSig      = trim($input['formSignature'] ?? '');

if (empty($fields) || empty($customerData) || !$formSig) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// ── CACHE KEY: form signature + available customer data keys (so different customers don't share cache) ──
$availableKeys = array_keys(array_filter($customerData, fn($v) => !empty($v)));
sort($availableKeys);
$cacheKey = $formSig . '_' . md5(implode(',', $availableKeys));

// ── CACHE CHECK (local file) ──────────────────────────────────────────────────
$cachedMapping = cacheGet($cacheKey);
if ($cachedMapping !== null) {
    echo json_encode([
        'success'     => true,
        'mapping'     => (object)$cachedMapping,
        'cached'      => true,
        'mappedCount' => count($cachedMapping),
    ]);
    exit();
}

// ── DERIVE NAME PARTS ─────────────────────────────────────────────────────────
// Port of content.js deriveNameParts() — builds firstName/middleName/lastName from fullName
function deriveNameParts(array $data): array {
    if (empty($data['firstName']) && !empty($data['fullName'])) {
        $parts = preg_split('/\s+/', trim($data['fullName']), -1, PREG_SPLIT_NO_EMPTY);
        $n = count($parts);
        if ($n === 1) {
            $data['firstName']  = $parts[0];
            $data['middleName'] = '';
            $data['lastName']   = '';
        } elseif ($n === 2) {
            $data['firstName']  = $parts[0];
            $data['middleName'] = '';
            $data['lastName']   = $parts[1];
        } else {
            $data['firstName']  = $parts[0];
            $data['lastName']   = $parts[$n - 1];
            $data['middleName'] = implode(' ', array_slice($parts, 1, -1));
        }
    }
    return $data;
}
$customerData = deriveNameParts($customerData);

// ── LOCAL RULE MAPPING ────────────────────────────────────────────────────────
// PHP port of content.js localRuleMapping() — 160+ rules covering all Indian govt form fields.
// To add support for a new portal or field: just add a rule here, live immediately.
function localRuleMapping(array $fields, array $customerData): array {
    $mapping = [];
    $used    = [];

    $rules = [
        // ══ NAMES ══
        ['keys' => ['first name','firstname','first_name','प्रथम नाम','पहला नाम','given name',
                    'fname','txtfname','txt_fname','f_name','applicant first','candidate first',
                    "applicant's first",'applicant first name',"candidate's first",
                    'name (first','(first name','first name of'],
         'dataKey' => 'firstName'],
        ['keys' => ['middle name','middlename','middle_name','मध्य नाम','second name',
                    'mname','txtmname','txt_mname','m_name','mid name',
                    "applicant's middle",'applicant middle name',"candidate's middle",
                    'name (middle','(middle name'],
         'dataKey' => 'middleName'],
        ['keys' => ['last name','lastname','last_name','surname','उपनाम','अंतिम नाम','family name',
                    'lname','txtlname','txt_lname','l_name','sname','txtsname','sur name',
                    "applicant's last","applicant's surname",'applicant last name',
                    "candidate's last","candidate's surname",
                    'name (last','(last name','(surname'],
         'dataKey' => 'lastName'],
        ['keys' => ['full name','fullname','candidate name','applicant name',
                    'name of candidate','name of applicant','पूरा नाम','आवेदक का नाम',
                    'name as on aadhaar','name as per aadhaar','name on aadhaar',
                    "applicant's name","candidate's name",'your name'],
         'dataKey' => 'fullName'],
        // ══ FAMILY ══
        ['keys' => ["father's name","father name","fathername","पिता का नाम","पिता"],
         'dataKey' => 'fatherName'],
        ['keys' => ["mother's name","mother name","mothername","माता का नाम","माता"],
         'dataKey' => 'motherName'],
        ['keys' => ['husband name',"husband's name",'spouse name','guardian name'],
         'dataKey' => 'husbandName'],
        // ══ PERSONAL ══
        ['keys' => ['date of birth','dob','birth date','d.o.b','जन्म तिथि','जन्म की तारीख',
                    'txtdob','dateofbirth','dt_birth','date of birth (dd/mm/yyyy)',
                    'date of birth (dd-mm-yyyy)','dob (dd/mm/yyyy)',
                    "applicant's date of birth","candidate's date of birth"],
         'dataKey' => 'dob'],
        ['keys' => ['gender','sex','लिंग'],                                           'dataKey' => 'gender'],
        ['keys' => ['category','caste','जाति','वर्ग','social category','reservation category'],
         'dataKey' => 'category'],
        ['keys' => ['religion','धर्म'],                                               'dataKey' => 'religion'],
        ['keys' => ['nationality','राष्ट्रीयता'],                                    'dataKey' => 'nationality'],
        ['keys' => ['marital status','marital','वैवाहिक स्थिति','वैवाहिक'],          'dataKey' => 'maritalStatus'],
        ['keys' => ['annual income','income','वार्षिक आय','आय'],                     'dataKey' => 'annualIncome'],
        ['keys' => ['identification mark 1','ident mark 1','identifying mark 1','पहचान चिह्न 1','mark 1'],
         'dataKey' => 'identMark1'],
        ['keys' => ['identification mark 2','ident mark 2','identifying mark 2','पहचान चिह्न 2','mark 2'],
         'dataKey' => 'identMark2'],
        ['keys' => ['employed','currently employed','employment status'],              'dataKey' => 'employed'],
        ['keys' => ['employment exchange','emp exchange no','रोजगार कार्यालय'],       'dataKey' => 'empExchangeNo'],
        ['keys' => ['criminal case','criminal conviction','अपराधिक मामला'],           'dataKey' => 'criminalCase'],
        ['keys' => ['debarred','disqualified','प्रतिबंधित'],                         'dataKey' => 'debarred'],
        // ══ CONTACT ══
        ['keys' => ['mobile number','mobile no','mobile','phone number','phone','मोबाइल','फोन','contact'],
         'dataKey' => 'mobileNumber', 'multi' => true],
        ['keys' => ['alternate mobile','alt mobile','alternative mobile','other mobile','वैकल्पिक मोबाइल'],
         'dataKey' => 'altMobile'],
        ['keys' => ['email address','email id','email','ईमेल','e-mail'],
         'dataKey' => 'emailId', 'multi' => true],
        // ══ CONFIRM FIELDS ══
        ['keys' => ['re-enter name','retype name','re enter name','confirm name','verify name','reenter name','re-type name'],
         'dataKey' => 'fullName',        'confirm' => true],
        ['keys' => ['re-enter date of birth','re-enter dob','confirm dob','retype dob','reenter dob','verify dob','re enter dob','re-enter date'],
         'dataKey' => 'dob',             'confirm' => true],
        ['keys' => ["re-enter father","retype father","re enter father","confirm father"],
         'dataKey' => 'fatherName',      'confirm' => true],
        ['keys' => ["re-enter mother","retype mother","re enter mother","confirm mother"],
         'dataKey' => 'motherName',      'confirm' => true],
        ['keys' => ['re-enter aadhaar','retype aadhaar','confirm aadhaar','re enter aadhaar','re-enter aadhar','confirm aadhar'],
         'dataKey' => 'aadhaarNumber',   'confirm' => true],
        ['keys' => ['re-enter name as on aadhaar','confirm name as on aadhaar','re-enter name on aadhaar','retype name on aadhar'],
         'dataKey' => 'fullName',        'confirm' => true],
        ['keys' => ['confirm mobile','re-enter mobile','retype mobile','re enter mobile','verify mobile','confirm phone','re-enter phone'],
         'dataKey' => 'mobileNumber',    'confirm' => true],
        ['keys' => ['confirm email','re-enter email','retype email','re enter email','verify email','confirm mail','re-enter mail'],
         'dataKey' => 'emailId',         'confirm' => true],
        // ══ PERMANENT ADDRESS ══
        ['keys' => ['permanent address','perm address','house no','flat no','door no',
                    'street','village','locality','स्थायी पता','मकान नंबर','पता'],
         'dataKey' => 'permAddr1', 'multi' => true],
        ['keys' => ['address line 2','addr line 2','area','colony','mohalla','perm addr 2','permanent address 2'],
         'dataKey' => 'permAddr2', 'multi' => true],
        ['keys' => ['post office','perm po','p.o.','डाकघर','पोस्ट ऑफिस'],
         'dataKey' => 'permPO',    'multi' => true],
        ['keys' => ['police station','perm ps','p.s.','थाना'],
         'dataKey' => 'permPS',    'multi' => true],
        ['keys' => ['permanent district','perm district','district','city','town','शहर','जिला'],
         'dataKey' => 'permDistrict', 'multi' => true],
        ['keys' => ['permanent state','perm state','state','राज्य'],
         'dataKey' => 'permState', 'multi' => true],
        ['keys' => ['permanent pin','perm pin','pincode','pin code','postal code','zip','पिनकोड'],
         'dataKey' => 'permPin',   'multi' => true],
        // ══ CORRESPONDENCE ADDRESS ══
        ['keys' => ['correspondence address','corr address','present address','current address','mailing address','temporary address','वर्तमान पता'],
         'dataKey' => 'permAddr1',    'confirm' => true],
        ['keys' => ['corr addr 2','correspondence address 2','present address 2'],
         'dataKey' => 'permAddr2',    'confirm' => true],
        ['keys' => ['corr po','correspondence post office','present post office'],
         'dataKey' => 'permPO',       'confirm' => true],
        ['keys' => ['corr ps','correspondence police station','present police station'],
         'dataKey' => 'permPS',       'confirm' => true],
        ['keys' => ['corr district','correspondence district','present district','current district'],
         'dataKey' => 'permDistrict', 'confirm' => true],
        ['keys' => ['corr state','correspondence state','present state','current state'],
         'dataKey' => 'permState',    'confirm' => true],
        ['keys' => ['corr pin','correspondence pin','present pin','current pin','present pincode'],
         'dataKey' => 'permPin',      'confirm' => true],
        // ══ IDENTITY DOCUMENTS ══
        ['keys' => ['aadhaar','aadhar','aadhaar number','आधार','uid'],                'dataKey' => 'aadhaarNumber'],
        ['keys' => ['pan number','pan card','pan no','पैन'],                          'dataKey' => 'panNumber'],
        ['keys' => ['voter id','voter number','voter card','epic','मतदाता'],          'dataKey' => 'voterId'],
        ['keys' => ['passport number','passport no','पासपोर्ट'],                     'dataKey' => 'passportNumber'],
        ['keys' => ['driving licence','driving license','dl number','dl no','ड्राइविंग लाइसेंस'],
         'dataKey' => 'dlNumber'],
        // ══ BANK ══
        ['keys' => ['account number','bank account','acc number','खाता संख्या'],     'dataKey' => 'bankAcc'],
        ['keys' => ['ifsc','ifsc code','आईएफएससी'],                                  'dataKey' => 'bankIFSC'],
        ['keys' => ['bank name','बैंक का नाम'],                                      'dataKey' => 'bankName'],
        ['keys' => ['branch name','bank branch','शाखा'],                             'dataKey' => 'bankBranch'],
        // ══ DISABILITY ══
        ['keys' => ['disability type','type of disability','विकलांगता का प्रकार'],   'dataKey' => 'disabilityType'],
        ['keys' => ['disability percent','disability percentage','विकलांगता प्रतिशत'],'dataKey' => 'disabilityPercent'],
        ['keys' => ['pwd certificate','disability certificate no'],                   'dataKey' => 'pwdCertNo'],
        ['keys' => ['pwd','person with disability','is pwd','divyang'],               'dataKey' => 'isPwd'],
        // ══ CERTIFICATES ══
        ['keys' => ['domicile certificate','domicile cert no','मूल निवास प्रमाण पत्र'],'dataKey' => 'domicileCertNo'],
        ['keys' => ['domicile issue date','domicile date'],                           'dataKey' => 'domicileIssueDate'],
        ['keys' => ['domicile authority','domicile issuing authority'],               'dataKey' => 'domicileAuthority'],
        ['keys' => ['caste certificate','caste cert no','जाति प्रमाण पत्र'],         'dataKey' => 'casteCertNo'],
        ['keys' => ['caste issue date','caste date'],                                 'dataKey' => 'casteIssueDate'],
        ['keys' => ['caste authority','caste issuing authority'],                     'dataKey' => 'casteAuthority'],
        ['keys' => ['caste issue district','caste certificate district'],             'dataKey' => 'casteIssueDistrict'],
        ['keys' => ['income certificate','income cert no','आय प्रमाण पत्र'],         'dataKey' => 'incomeCertNo'],
        ['keys' => ['income issue date','income cert date'],                          'dataKey' => 'incomeIssueDate'],
        // ══ EDUCATION: 10TH ══
        ['keys' => ['10th board','tenth board','matriculation board','matric board','high school board','class x board'],
         'dataKey' => 'tenthBoard'],
        ['keys' => ['10th year','tenth year','matric year','class x year','10th pass year'],
         'dataKey' => 'tenthYear'],
        ['keys' => ['10th percent','10th percentage','matric percent','class x percentage'],
         'dataKey' => 'tenthPercent'],
        ['keys' => ['10th roll','matric roll','class x roll'],                        'dataKey' => 'tenthRoll'],
        ['keys' => ['10th cert','matric certificate no'],                             'dataKey' => 'tenthCertNo'],
        ['keys' => ['10th obtained','marks obtained 10'],                             'dataKey' => 'tenthObtained'],
        ['keys' => ['10th total','total marks 10'],                                   'dataKey' => 'tenthTotal'],
        ['keys' => ['10th division','matric division','class x division'],            'dataKey' => 'tenthDivision'],
        // ══ EDUCATION: 12TH ══
        ['keys' => ['12th board','twelfth board','intermediate board','hsc board','class xii board'],
         'dataKey' => 'twelfthBoard'],
        ['keys' => ['12th year','twelfth year','intermediate year','class xii year'], 'dataKey' => 'twelfthYear'],
        ['keys' => ['12th percent','12th percentage','intermediate percent','class xii percentage'],
         'dataKey' => 'twelfthPercent'],
        ['keys' => ['12th roll','inter roll','class xii roll'],                       'dataKey' => 'twelfthRoll'],
        ['keys' => ['12th cert','intermediate cert no'],                              'dataKey' => 'twelfthCertNo'],
        ['keys' => ['12th obtained','marks obtained 12'],                             'dataKey' => 'twelfthObtained'],
        ['keys' => ['12th total','total marks 12'],                                   'dataKey' => 'twelfthTotal'],
        ['keys' => ['12th stream','inter stream','hsc stream'],                       'dataKey' => 'twelfthStream'],
        // ══ DIPLOMA ══
        ['keys' => ['diploma board','polytechnic board'],                             'dataKey' => 'diplomaBoard'],
        ['keys' => ['diploma year','diploma pass year'],                              'dataKey' => 'diplomaYear'],
        ['keys' => ['diploma percent','diploma percentage'],                          'dataKey' => 'diplomaPercent'],
        ['keys' => ['diploma trade','diploma branch','diploma stream'],               'dataKey' => 'diplomaTrade'],
        ['keys' => ['diploma roll'],                                                  'dataKey' => 'diplomaRoll'],
        // ══ GRADUATION ══
        ['keys' => ['graduation degree','ug degree','bachelor degree','b.a','b.sc','b.com',
                    'graduation','undergraduate','स्नातक'],                          'dataKey' => 'gradDegree'],
        ['keys' => ['graduation university','ug university','grad univ','bachelor university'],
         'dataKey' => 'gradUniv'],
        ['keys' => ['graduation year','ug year','grad year','bachelor pass year'],    'dataKey' => 'gradYear'],
        ['keys' => ['graduation percent','ug percent','grad percent','bachelor percentage'],
         'dataKey' => 'gradPercent'],
        ['keys' => ['graduation roll','ug roll','grad roll'],                         'dataKey' => 'gradRoll'],
        ['keys' => ['degree issue date','graduation date','convocation date'],        'dataKey' => 'gradIssueDate'],
        // ══ POST GRADUATION ══
        ['keys' => ['post graduation','pg degree','master degree','m.a','m.sc','m.com',
                    'mba','mca','m.tech','postgraduate','स्नातकोत्तर'],             'dataKey' => 'pgDegree'],
        ['keys' => ['pg university','post graduation university','master university'],'dataKey' => 'pgUniv'],
        ['keys' => ['pg year','post graduation year','master pass year'],             'dataKey' => 'pgYear'],
        ['keys' => ['pg percent','post graduation percent','master percentage'],      'dataKey' => 'pgPercent'],
        ['keys' => ['pg roll','post graduation roll'],                                'dataKey' => 'pgRoll'],
        // ══ E-SHRAM ══
        ['keys' => ['occupation','type of work','profession','व्यवसाय','पेशा'],      'dataKey' => 'occupation'],
        ['keys' => ['nature of work','skill type','कार्य की प्रकृति'],               'dataKey' => 'natureOfWork'],
        ['keys' => ['monthly income','monthly wage','मासिक आय','मासिक वेतन'],       'dataKey' => 'monthlyIncome'],
        ['keys' => ['daily wage','daily income','दैनिक मजदूरी','दैनिक वेतन'],       'dataKey' => 'dailyWage'],
        ['keys' => ['work experience','years of experience','experience in years'],   'dataKey' => 'workExperience'],
        ['keys' => ['eshram','e-shram','uan number','shramik card'],                  'dataKey' => 'eshramNumber'],
        ['keys' => ['nominee name','नॉमिनी का नाम'],                                'dataKey' => 'nomineeName'],
        ['keys' => ['nominee relation','relation with nominee'],                      'dataKey' => 'nomineeRelation'],
        ['keys' => ['nominee dob','nominee date of birth','नॉमिनी जन्म तिथि'],      'dataKey' => 'nomineeDob'],
        ['keys' => ['nominee aadhaar','nominee aadhar'],                              'dataKey' => 'nomineeAadhaar'],
        // ══ PM KISAN ══
        ['keys' => ['khasra','khasra number','खसरा','खसरा नंबर'],                   'dataKey' => 'khasraNumber'],
        ['keys' => ['khatauni','khatauni number','खतौनी','खतौनी नंबर'],             'dataKey' => 'khatauniNumber'],
        ['keys' => ['land area','land in acres','भूमि','भूमि क्षेत्र','जमीन'],      'dataKey' => 'landArea'],
        ['keys' => ['land type','type of land','भूमि प्रकार'],                      'dataKey' => 'landType'],
        ['keys' => ['survey number','survey no','खेत सर्वे','सर्वे नंबर'],          'dataKey' => 'surveyNumber'],
        ['keys' => ['tehsil','taluka','तहसील','तालुका'],                             'dataKey' => 'tehsil'],
        ['keys' => ['gram sabha','gram panchayat','village name','ग्राम सभा','ग्राम पंचायत'],
         'dataKey' => 'gramSabha'],
        ['keys' => ['pm kisan reg','pm kisan registration','किसान पंजीकरण'],         'dataKey' => 'pmKisanRegNo'],
        // ══ RATION / AYUSHMAN ══
        ['keys' => ['ration card number','ration card no','राशन कार्ड'],             'dataKey' => 'rationCardNumber'],
        ['keys' => ['ration card type','ration type','राशन कार्ड प्रकार'],          'dataKey' => 'rationCardType'],
        ['keys' => ['family members','number of members','परिवार के सदस्य'],         'dataKey' => 'familyMemberCount'],
        ['keys' => ['ayushman','abha number','health id','आयुष्मान'],                'dataKey' => 'ayushmanCardNumber'],
        ['keys' => ['secc','nfsa id','secc id'],                                      'dataKey' => 'seccId'],
        // ══ OTHER SCHEMES ══
        ['keys' => ['jan dhan','जन धन'],                                             'dataKey' => 'janDhanAcc'],
        ['keys' => ['mnrega','job card','नरेगा','मनरेगा'],                           'dataKey' => 'mnregaJobCard'],
        ['keys' => ['pension type','pension scheme','पेंशन प्रकार'],                 'dataKey' => 'pensionType'],
        ['keys' => ['pension registration','pension reg no'],                         'dataKey' => 'pensionRegNo'],
    ];

    // Labels containing these words indicate the field should NOT be auto-filled
    // (OTP fields, certificate reference numbers, application IDs, etc.)
    $skipKeywords = [
        'otp','one time password','verification code','captcha',
        'ref no','ref. no','reference no','reference number','application ref',
        'reg no','reg. no','registration no','registration number',
        'cert no','cert. no','certificate no','certificate number',
        'application no','application number','application id',
        'token','serial no','acknowledge',
        'third gender','तृतीय लिंग',   // gender-specific field for trans persons only
    ];

    foreach ($fields as $field) {
        $label = strtolower(
            ($field['label'] ?? '') . ' ' . ($field['name'] ?? '') . ' ' . ($field['id'] ?? '')
        );

        // Skip fields that are clearly not meant for personal data auto-fill
        $shouldSkip = false;
        foreach ($skipKeywords as $sk) {
            if (str_contains($label, $sk)) { $shouldSkip = true; break; }
        }
        if ($shouldSkip) continue;

        foreach ($rules as $rule) {
            $isMulti   = !empty($rule['multi']);
            $isConfirm = !empty($rule['confirm']);
            $dataKey   = $rule['dataKey'];
            // Skip already-used keys unless multi or confirm
            if (isset($used[$dataKey]) && !$isMulti && !$isConfirm) continue;
            // Skip if customer doesn't have this data
            if (empty($customerData[$dataKey])) continue;
            foreach ($rule['keys'] as $k) {
                if (str_contains($label, $k)) {
                    $mapping[(string)$field['index']] = $dataKey;
                    if (!$isMulti) $used[$dataKey] = true;
                    break 2; // break both loops — found a match
                }
            }
        }
    }
    // ── DIRECT ID/NAME FALLBACK ──────────────────────────────────────────────────
    // If field id or name exactly matches a customer data key, use it directly.
    // Handles forms where field names match data keys: tenthYear, gradUniv, etc.
    foreach ($fields as $field) {
        $idx = (string)$field['index'];
        if (isset($mapping[$idx])) continue; // already mapped by rules above
        // Skip sensitive/OTP/reference fields for direct fallback too
        $labelCheck = strtolower(($field['label'] ?? '') . ' ' . ($field['name'] ?? '') . ' ' . ($field['id'] ?? ''));
        $skipThis = false;
        foreach ($skipKeywords as $sk) { if (str_contains($labelCheck, $sk)) { $skipThis = true; break; } }
        if ($skipThis) continue;
        $fieldId   = strtolower($field['id']   ?? '');
        $fieldName = strtolower($field['name'] ?? '');
        foreach ($customerData as $dataKey => $value) {
            if (empty($value)) continue;
            if ($fieldId === strtolower($dataKey) || $fieldName === strtolower($dataKey)) {
                $mapping[$idx] = $dataKey;
                break;
            }
        }
    }

    return $mapping;
}

// ── NAME-BASED RULES (for education grids, SSB-style forms) ──────────────────
// Many forms use same label for all columns — match by field name/id instead
function nameBasedMapping(array $fields, array $customerData): array {
    $mapping = [];

    // Map: field name pattern → customer data key
    $nameRules = [
        // ── Education: Matriculation / 10th ──
        'university_1'       => 'tenthBoard',
        'edu_year_1'         => 'tenthYear',
        'edu_rollno_1'       => 'tenthRoll',
        'edu_certno_1'       => 'tenthCertNo',
        'edu_marks_1'        => 'tenthPercent',
        'stream_1'           => 'tenthDivision',
        // ── Education: 10+2 / 12th ──
        'university_plus2'   => 'twelfthBoard',
        'edu_year_plus2'     => 'twelfthYear',
        'edu_rollno_plus2'   => 'twelfthRoll',
        'edu_certno_plus2'   => 'twelfthCertNo',
        'edu_marks_plus2'    => 'twelfthPercent',
        'stream_plus2'       => 'twelfthStream',
        // ── Education: Diploma ──
        'university_diploma' => 'diplomaBoard',
        'university_5'       => 'diplomaBoard',
        'edu_year_5'         => 'diplomaYear',
        'edu_rollno_5'       => 'diplomaRoll',
        'edu_certno_5'       => 'twelfthCertNo',
        'edu_marks_5'        => 'diplomaPercent',
        'stream_5'           => 'diplomaTrade',
        // ── Education: Graduation ──
        'university_3'       => 'gradUniv',
        'edu_year_3'         => 'gradYear',
        'edu_rollno_3'       => 'gradRoll',
        'edu_marks_3'        => 'gradPercent',
        'stream_3'           => 'gradDegree',
        // ── Education: Post Graduation ──
        'university_4'       => 'pgUniv',
        'edu_year_4'         => 'pgYear',
        'edu_rollno_4'       => 'pgRoll',
        'edu_marks_4'        => 'pgPercent',
        'stream_4'           => 'pgDegree',
        // ── Caste Certificate ──
        'cat_cert_no'        => 'casteCertNo',
        'cat_date_issue'     => 'casteIssueDate',
        'cat_issue_authority'=> 'casteAuthority',
        // ── Identification ──
        'identification_mark'=> 'identMark1',
        'id_mark'            => 'identMark1',
        'id_mark_1'          => 'identMark1',
        'id_mark_2'          => 'identMark2',
        // ── Address ──
        'tehsil'             => 'tehsil',
        'tehsil1'            => 'tehsil',
        // ── Radio: Yes/No fields ──
        'debarment'          => 'debarred',
        'fir_cases'          => 'criminalCase',
        'fir_cases_pending'  => 'criminalCase',
        'arrested'           => 'criminalCase',
        'criminal_case_acquitted' => 'criminalCase',
        'good_behavior_bond' => 'criminalCase',
        'middle_name'        => 'middleName',
    ];

    foreach ($fields as $field) {
        $idx      = (string)$field['index'];
        $fname    = strtolower($field['name'] ?? '');
        $fid      = strtolower($field['id']   ?? '');

        foreach ($nameRules as $pattern => $dataKey) {
            if (empty($customerData[$dataKey])) continue;
            if ($fname === $pattern || $fid === $pattern) {
                $mapping[$idx] = $dataKey;
                break;
            }
        }
    }
    return $mapping;
}

function openaiContentToText($content): string {
    if (is_string($content)) {
        return $content;
    }

    if (is_array($content)) {
        $chunks = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                $chunks[] = $item;
                continue;
            }
            if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                $chunks[] = $item['text'];
            }
        }
        return implode("\n", $chunks);
    }

    return '';
}

$localMapping    = localRuleMapping($fields, $customerData);
$nameMapping     = nameBasedMapping($fields, $customerData);
// Merge: label rules win, name rules fill gaps
foreach ($nameMapping as $idx => $key) {
    if (!isset($localMapping[$idx])) $localMapping[$idx] = $key;
}

// ── FIND UNMAPPED FIELDS ──────────────────────────────────────────────────────
$unmappedFields = [];
foreach ($fields as $field) {
    if (!isset($localMapping[(string)$field['index']])) {
        $unmappedFields[] = $field;
    }
}

// ── AI AGENT FOR UNMAPPED FIELDS ─────────────────────────────────────────────
// Sends all unmapped fields + full customer data to AI for intelligent mapping.
// AI understands Hindi/English labels, field intent, and context — not just keywords.
// Debug log — writes what AI sees and returns
$debugLog = __DIR__ . '/cache/debug_' . $formSig . '.json';

$aiMapping = [];
$aiMetrics = null;
if (!empty($unmappedFields) && function_exists('curl_init')) {
    // Build clean customer data with descriptions for AI context
    $cleanData = [];
    foreach ($customerData as $k => $v) {
        if (!is_null($v) && $v !== '' && $v !== 'null' && !is_array($v) && !is_object($v)) {
            $cleanData[$k] = $v;
        }
    }

    // Build field list with full context for AI
    $fieldsForAI = array_map(function($f) {
        return [
            'index' => $f['index'],
            'label' => $f['label'] ?? '',
            'name'  => $f['name']  ?? '',
            'id'    => $f['id']    ?? '',
            'type'  => $f['type']  ?? 'text',
        ];
    }, $unmappedFields);

    $cleanDataJson = json_encode($cleanData,   JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $fieldsJson    = json_encode($fieldsForAI, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $prompt =
        "You are an expert form-filling AI for Indian government forms.\n"
        . "Your job: match each form field to the correct customer data key.\n\n"
        . "CUSTOMER DATA (keys and values):\n" . $cleanDataJson . "\n\n"
        . "FORM FIELDS TO MAP:\n" . $fieldsJson . "\n\n"
        . "CRITICAL RULES:\n"
        . "1. Understand both English AND Hindi labels (e.g. 'पिता का नाम' = Father's Name)\n"
        . "2. Match field INTENT, not just keywords:\n"
        . "   - 'प्रथम नाम / First Name' → firstName\n"
        . "   - 'मध्य नाम / Middle Name' → middleName\n"
        . "   - 'अंतिम नाम / Surname / Last Name' → lastName\n"
        . "   - 'पूरा नाम / Full Name / Applicant Name' → fullName\n"
        . "   - 'पिता का नाम / Father Name' → fatherName\n"
        . "   - 'माता का नाम / Mother Name' → motherName\n"
        . "   - 'जन्म तिथि / Date of Birth / DOB' → dob\n"
        . "   - 'लिंग / Gender / Sex' (NOT third gender) → gender\n"
        . "   - 'जाति / Category / Caste Category' (NOT cert no.) → category\n"
        . "   - 'मोबाइल / Mobile / Phone' → mobileNumber\n"
        . "   - 'ईमेल / Email' → emailId\n"
        . "   - 'आधार / Aadhaar' → aadhaarNumber\n"
        . "   - 'पिन / PIN Code / Pincode' → permPin\n"
        . "   - 'जिला / District / City' → permDistrict\n"
        . "   - 'राज्य / State' → permState\n"
        . "3. NEVER fill these field types — return null:\n"
        . "   - OTP fields, CAPTCHA, verification codes\n"
        . "   - Reference numbers, application numbers, certificate numbers\n"
        . "   - 'तृतीय लिंग / Third Gender' specific fields\n"
        . "   - Confirmation/re-enter fields that would duplicate nearby fields\n"
        . "   - Fields about occupation, organization, post applied for\n"
        . "4. NEVER assign a value to the wrong field type (e.g. don't put text in date fields)\n"
        . "5. If unsure — return null. Wrong fill is worse than no fill.\n\n"
        . "Return ONLY valid JSON object: {\"fieldIndex\": \"dataKey\", ...}\n"
        . "Use null for fields that should not be filled.\n"
        . "Example: {\"5\": \"permDistrict\", \"6\": null, \"7\": \"permState\"}";

    $openaiApiKey = getenv('OPENAI_API_KEY');
    $openaiTextModel = getenv('OPENAI_TEXT_MODEL') ?: 'gpt-4o-mini';
    $openaiEndpoint = getenv('OPENAI_API_ENDPOINT') ?: 'https://api.openai.com/v1/chat/completions';

    $basePayload = [
        'model' => $openaiTextModel,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a precise form-filling AI for Indian government forms. Return only valid JSON. Never explain, never add text outside JSON. Use ONLY the exact data keys from CUSTOMER DATA.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.0,
        'max_tokens' => 6000,
    ];
    $attemptPayloads = [
        $basePayload + ['response_format' => ['type' => 'json_object']],
        $basePayload,
    ];
    $raw = '';
    $decoded = [];

    foreach ($attemptPayloads as $attemptPayload) {
        $payload = json_encode($attemptPayload, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            continue;
        }

        $ch = curl_init($openaiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $openaiApiKey,
            ],
            CURLOPT_TIMEOUT => 45,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) {
            continue;
        }

        $oaiData = json_decode($resp, true);
        if (is_array($oaiData)) {
            $aiMetrics = openaiBuildUsageMeta($openaiTextModel, $oaiData);
        }

        $raw = openaiContentToText($oaiData['choices'][0]['message']['content'] ?? '{}');
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $raw = trim($raw);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);
        }

        if (is_array($decoded)) {
            // Only accept keys that actually exist in customerData — prevents wrong key names
            $validKeys = array_keys($cleanData);
            foreach ($decoded as $idx => $key) {
                if (!is_null($key) && $key !== '' && is_string($key) && in_array($key, $validKeys, true)) {
                    $aiMapping[$idx] = $key;
                }
            }
            break;
        }
    }
    // If AI fails: aiMapping stays [] — local rules are still returned

    // Write debug log
    file_put_contents($debugLog, json_encode([
        'unmapped_fields_sent_to_ai' => $fieldsForAI,
        'ai_raw_response'            => $raw ?? '',
        'ai_mapping_returned'        => $decoded ?? [],
        'ai_mapping_accepted'        => $aiMapping,
        'ai_metrics'                 => $aiMetrics,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── MERGE — local rules win over AI ──────────────────────────────────────────
// Local rules always win — AI only fills gaps for fields not covered by local rules
$finalMapping = $localMapping;
foreach ($aiMapping as $idx => $key) {
    if (!isset($finalMapping[$idx])) {
        $finalMapping[$idx] = $key;
    }
}

// ── CACHE TO LOCAL FILE ───────────────────────────────────────────────────────
if (!empty($finalMapping)) {
    cacheSave($cacheKey, $finalMapping);
}

// ── RESPOND ───────────────────────────────────────────────────────────────────
echo json_encode([
    'success'     => true,
    'mapping'     => (object)$finalMapping,
    'cached'      => false,
    'mappedCount' => count($finalMapping),
    'aiUsed'      => !empty($aiMapping),
    'metrics'     => ['agentAi' => $aiMetrics],
]);
