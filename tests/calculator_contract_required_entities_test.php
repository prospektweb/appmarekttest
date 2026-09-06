<?php
require_once dirname(__DIR__) . '/lib/Services/CalculatorContractService.php';
use Prospektweb\Calc\Services\CalculatorContractService as Contract;

$enums = [['ID'=>301,'XML_ID'=>'VARIANT_MATERIAL'],['ID'=>302,'XML_ID'=>'EQUIPMENT'],['ID'=>303,'XML_ID'=>'VARIANT_OPERATION']];
if (Contract::requiredEntityEnumIds(['EQUIPMENT','VARIANT_MATERIAL','EQUIPMENT'],$enums) !== [302,301]) throw new RuntimeException('Stable codes must map to target-iblock enums and deduplicate');
foreach ([[],['UNKNOWN']] as $codes) {
    $failed=false;
    try { Contract::requiredEntityEnumIds($codes,$enums); } catch (RuntimeException $e) { $failed=true; }
    if (!$failed) throw new RuntimeException('Missing required capability must fail instead of inventing an entity');
}
$source=file_get_contents(dirname(__DIR__).'/lib/Services/CalculatorContractService.php');
if (!str_contains($source,"'CODE' => 'calc_'")) throw new RuntimeException('The copied calculator requires its own symbolic code');

// Bitrix returns no properties when GetNextElement lacks its selected IBLOCK_ID.
class CIBlockElement {
    public static function GetList($order, $filter, $group, $navigation, $select) {
        return new class($filter, $select) {
            private bool $read = false;
            public function __construct(private array $filter, private array $select) {}
            public function GetNextElement() {
                if ($this->read) return false;
                $this->read = true;
                return new class($this->filter, $this->select) {
                    public function __construct(private array $filter, private array $select) {}
                    public function GetFields() { return ['ID'=>71]; }
                    public function GetProperties($order, $filter) {
                        if (!in_array('IBLOCK_ID', $this->select, true)) return [];
                        $code=$filter['CODE'];
                        return [$code=>['VALUE'=>$code==='CALC_SETTINGS' ? 101 : [11,12,99]]];
                    }
                };
            }
        };
    }
}
$contract=new Contract([]);
$propertyReader=new ReflectionMethod(Contract::class,'loadPropertyIds');
if ($propertyReader->invoke($contract,42,11,'CALC_SETTINGS')!==[101]) throw new RuntimeException('Clone attachment must read actual Bitrix properties');
$detailReader=new ReflectionMethod(Contract::class,'loadDetailStageMap');
if ($detailReader->invoke($contract,44,[11,12])!==[71=>[11,12]]) throw new RuntimeException('Dependency index must read and filter detail stages');
$copy=new ReflectionMethod(Contract::class,'copyPropertyValue');
$raw=['TEXT'=>'{"version":2,"vars":[]}','TYPE'=>'HTML'];
if ($copy->invoke($contract,['VALUE'=>['TEXT'=>'{&quot;version&quot;:2}','TYPE'=>'HTML'],'~VALUE'=>$raw])!==$raw) throw new RuntimeException('HTML logic JSON must use the unescaped Bitrix value');
if ($copy->invoke($contract,['VALUE'=>['x'],'DESCRIPTION'=>['&quot;title&quot;'],'~DESCRIPTION'=>['"title"'],'WITH_DESCRIPTION'=>'Y'])!==[['VALUE'=>'x','DESCRIPTION'=>'"title"']]) throw new RuntimeException('Descriptions must not be double escaped');
echo "Calculator required clone fields passed\n";
