<?php
declare(strict_types=1);

function workspace_table_row_token(array $record): string {
    return hash('sha256',$record['id'].'|'.$record['updated_at'].'|'.$record['archived'].'|'.$record['values_json']);
}

function workspace_table_row_action(array $body): array {
    $table=workspace_table();
    workspace_require('table',(string)$table['id'],'edit',$table['id']);
    $action=$body['action'];
    $row=$body['row']??null;
    if (!in_array($action,['check_delete','delete','archive','restore'],true) || !is_int($row) ||
        $row<1 || !is_string($body['rowToken']??null)) {
        throw new ApiError('invalid_row','Выберите сохранённую строку.');
    }
    $reason=$action==='delete' ? workspace_row_delete_reason($body['reason']??null) : null;
    workspace_begin();
    try {
        $record=wquery('SELECT * FROM records WHERE table_id=? AND row_num=? FOR UPDATE',[$table['id'],$row])->fetch();
        if (!$record || workspace_row_deleted('directory_deleted_record_',(int)$record['id'])) {
            throw new ApiError('not_found','Строка не найдена.',404);
        }
        if (!hash_equals(workspace_table_row_token($record),$body['rowToken'])) workspace_directory_conflict();
        $name='Строка '.$row;
        $wasArchived=(bool)$record['archived'];
        if ($action==='check_delete') {
            workspace_db()->commit();
            return ['name'=>$name];
        }
        $archived=$action!=='restore';
        if ($reason===null && $wasArchived===$archived) workspace_directory_conflict();
        wquery('UPDATE records SET archived=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[(int)$archived,$record['id']]);
        $after=['archived'=>$archived];
        if ($reason!==null) {
            workspace_mark_row_deleted('directory_deleted_record_',(int)$record['id']);
            $after+=['deleted'=>true,'reason'=>$reason];
        }
        workspace_audit(['category'=>'row','label'=>'Строка','tableId'=>$table['id'],'recordId'=>(int)$record['id'],
            'row'=>$row,'before'=>['archived'=>$wasArchived],'after'=>$after,'action'=>$action]);
        workspace_event('row.changed',['tableSlug'=>$table['slug'],'row'=>$row,'action'=>$action]);
        workspace_db()->commit();
        return ['name'=>$name];
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
