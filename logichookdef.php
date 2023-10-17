<?php
// Merged from custom/Extension/modules/TJ_Visits/Ext/LogicHooks/LH_patients.php


$hook_array['before_save'][] = array
(
    10,
   'Dont Allow multiple visits in Queue',
   'custom/modules/TJ_Visits/visit_patient.php',
   'visit_patient',
   'visit_patient_before_method'
);

$hook_array['after_save'][] = array
(
    10,
   'Rutines after save',
   'custom/modules/TJ_Visits/visit_patient.php',
   'visit_patient',
   'afterSave'
);

$hook_array['after_relationship_add'][] = array
(
    10,
   'After Relationship Add',
   'custom/modules/TJ_Visits/visit_patient.php',
   'visit_patient',
   'afterRelationshipAdd'
);




?>