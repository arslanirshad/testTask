<?php

// Merged from custom/Extension/modules/TJ_Visits/Ext/LogicHooks/LH_patients.php

$hook_array['before_save'][] = array(
    10,
    'Before Save Logic Hooks',
    'custom/modules/TJ_Visits/visitPatientLogicHooks.php', //You could also use namespaces
    'visitPatientClass',
    'before_save'
);

$hook_array['after_save'][] = array(
    10,
    'After Save Logic Hooks',
    'custom/modules/TJ_Visits/visitPatientLogicHooks.php',
    'visitPatientClass',
    'after_save'
);

$hook_array['after_relationship_add'][] = array(
    10,
    'After Relationship Add Logic Hooks',
    'custom/modules/TJ_Visits/visitPatientLogicHooks.php',
    'visitPatientClass',
    'after_relationship_add'
);
?>
