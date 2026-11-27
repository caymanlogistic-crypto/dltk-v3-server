<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') exit(1);
require_once __DIR__.'/../app/local-admin/src/bootstrap.php';
require_once __DIR__.'/../app/local-admin/src/Workspace.php';

if (!isset($argv[1]) || count($argv)>3 || (isset($argv[2]) && $argv[2]!=='--apply')) {
    fwrite(STDERR,"Usage: php tools/migrate_client_manager_accounts.php PRIVATE_MAPPING.json [--apply]\n");
    exit(1);
}

try {
    $mapping=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($mapping) || !array_is_list($mapping)) throw new RuntimeException('Expected a list of verified mappings.');
    $apply=isset($argv[2]);
    $run=static function () use ($mapping,$apply): array {
        if (workspace_client_account_managers_enabled()) return ['alreadyApplied'=>true];
        if ($apply) {
            try {
                $existing=wquery('SELECT 1 FROM client_account_manager_links LIMIT 1')->fetchColumn();
            } catch (PDOException $error) {
                if ($error->getCode()!=='42S02') throw $error;
                throw new RuntimeException('Prepare deploy/client_account_managers.sql before applying the migration.');
            }
            if ($existing) throw new RuntimeException('Account assignments already exist. Check the migration state.');
        }
        $legacy=array_column(workspace_manager_entries(workspace_manager_catalog_id()),null,'id');
        $accounts=array_column(workspace_employee_records(),null,'id');
        $byManager=[];
        foreach ($mapping as $pair) {
            if (!is_array($pair) || !is_int($pair['legacyId']??null) || !is_int($pair['accountId']??null) ||
                !is_string($pair['legacyName']??null) || !is_string($pair['accountName']??null)) {
                throw new RuntimeException('Invalid mapping row.');
            }
            $manager=$legacy[$pair['legacyId']]??null;
            $account=$accounts[$pair['accountId']]??null;
            if (!$manager || $manager['name']!==$pair['legacyName'] || !$account || $account['name']!==$pair['accountName'] ||
                !workspace_client_account_manager_eligible($account) || isset($byManager[$pair['legacyId']]) ||
                in_array($pair['accountId'],$byManager,true)) {
                throw new RuntimeException('A mapping is ambiguous, stale or no longer eligible.');
            }
            $byManager[$pair['legacyId']]=$pair['accountId'];
        }
        $rows=wquery("SELECT l.client_id,l.manager_id,l.position,m.name FROM client_manager_links l
            JOIN catalog_entries m ON m.id=l.manager_id JOIN catalog_entries c ON c.id=l.client_id
            JOIN catalogs d ON d.id=c.catalog_id WHERE d.kind='clients' ORDER BY l.client_id,l.position")->fetchAll();
        $linked=0;
        $unresolved=[];
        foreach ($rows as $row) {
            $managerId=(int)$row['manager_id'];
            $accountId=$byManager[$managerId]??null;
            if ($accountId!==null) ++$linked;
            else $unresolved[$managerId]=($unresolved[$managerId]??0)+1;
            if ($apply) wquery('INSERT INTO client_account_manager_links(client_id,position,account_id,legacy_manager_id,fallback_label)
                VALUES(?,?,?,?,?)',[$row['client_id'],$row['position'],$accountId,$accountId===null?$managerId:null,$row['name']]);
        }
        $summary=['clients'=>count(array_unique(array_column($rows,'client_id'))),'linked'=>$linked,
            'unresolved'=>array_sum($unresolved),'unresolvedManagers'=>count($unresolved)];
        if ($apply) {
            $copied=(int)wquery('SELECT COUNT(*) FROM client_account_manager_links')->fetchColumn();
            if ($copied!==count($rows)) throw new RuntimeException('Assignment count changed.');
            wquery('UPDATE catalog_entries e JOIN (SELECT DISTINCT client_id FROM client_account_manager_links) l ON l.client_id=e.id
                SET e.revision=e.revision+1');
            wquery("INSERT INTO workspace_meta VALUES('client_account_managers_v1','true')");
            wquery("INSERT INTO change_history(actor_name,column_label,after_value,action,category,operation_id)
                VALUES(?,?,?,'edit','catalog',?)",['Перенос назначений','Менеджеры клиента',wjson($summary),bin2hex(random_bytes(12))]);
        }
        return $summary+['applied'=>$apply];
    };
    echo wjson($apply ? workspace_transaction($run) : $run()),PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,($error instanceof ApiError ? $error->publicMessage : $error->getMessage()).PHP_EOL);
    exit(1);
}
