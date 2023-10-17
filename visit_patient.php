<?php

require_once "custom/modules/Contacts/ContactsUtils.php";
require_once "custom/modules/TJ_Clinics/TJ_ClinicsUtils.php";
require_once "custom/modules/TJ_Visits/TJ_VisitsUtils.php";
require_once "custom/modules/TJ_PriceBooks/TJ_PriceBooksUtils.php";
require_once "custom/modules/tjc_TreatmentPlans/tjc_TreatmentPlansUtils.php";
require_once "custom/modules/TJ_Purchases/logic_hooks/UpdateVisitsLeftCurrCycle.php";

class visit_patient 
{
    var $copyFields = array(
        //Adjustment Tab
        "cl0_c", "cr0_c", "lt1", "tl1", "tr1", "ll1", "lr1",
        "cl1", "cr1", "lt2", "tl2", "tr2", "ll2", "lr2",
        "cl2", "cr2", "lt3", "tl3", "tr3", "ll3", "lr3",
        "cl3", "cr3", "lt4", "tl4", "tr4", "ll4", "lr4",
        "cl4", "cr4", "lt5", "tl5", "tr5", "ll5", "lr5",
        "cl5", "cr5", "lt6", "tl6", "tr6", "pelvisl", "pelvisr",
        "cl6", "cr6", "lt7", "tl7", "tr7", "sacrum_l", "sacrum_r",
        "cl7", "cr7", "lt8", "tl8", "tr8", "lt9", "tl9", "tr9",
        "lt10", "tl10", "tr10", "lt11", "tl11", "tr11",
        "lt12", "tl12", "tr12",
        //Spinal
        "t1", "l1", "spinal_c0_c", "spinal_c1", "t2", "l2",
        "spinal_c2", "t3", "l3", "spinal_c3", "t4", "l4",
        "spinal_c4", "t5", "l5", "spinal_c5", "t6", 'lpelvis',
        "spinal_c6", "t7", 'rpelvis', "spinal_c7", "t8",  'lsacrum', 
        "t9", 'sacrumr', "t10", "t11", "t12",
        //Extremities/Ribs
        "tmjl_c", "tmjr_c", "shoulderl", "shoulderr",
        "sternl_c", "sternr_c", "acroml_c", "acromr_c",
        "ribsl", "ribr", "elbowl", "elbowr",
        "wristl", "wristr", "hipl", "hipr",
        "kneel", "kneer", "anklel", "ankler",
        'lpelvis', 'rpelvis', 'lsacrum', 'sacrumr',
        //Adjustment tab
        "sub_c0_c", "sub_c1", "sub_c2", "sub_c3", "sub_c4", "sub_c5", "sub_c6", "sub_c7",
        "sub_t1", "sub_t2", "sub_t3", "sub_t4", "sub_t5", "sub_t6", "sub_t7", "sub_t8",
        "sub_t9", "sub_t10", "sub_t11", "sub_t12", "sub_l1", "sub_l2", "sub_l3", "sub_l4",
        "sub_l5", "sub_pelL", "sub_pelR", "sub_sacrum", "sub_pelvis_c", "spinal_concern_c",
        //"referral", "sbw",
        //Current Hx tab
        "onsetcondition_c", "provocationcondition_c", "qualitypain_c", "regionpain_c", "timedescription_c",
    );

    static $already_ran = false;
    public function visit_patient_before_method($bean, $event, $arguments)
    {
        global $current_user;
        $main_patient_id = null;
        if (!$arguments['isUpdate']) {
            require_once 'include/SugarQuery/SugarQuery.php';
            //Obtain the id of the actual patient
            if ($bean->load_relationship('contacts_tj_visits_1')) {
                $relatedPatient = $bean->contacts_tj_visits_1->get();
                if (count($relatedPatient)) {
                    $main_patient_id = $relatedPatient[0];
                }
            }

            if(!is_null($main_patient_id)){
                //Query to get Visits with status  = pending notes or waitin queue
                $visitUtils = new TJ_VisitsUtils;
                $visitPendingNotes = $visitUtils->getVisitsByContactStatus($main_patient_id, 'Pending Notes', true);
                if ($visitPendingNotes && $visitPendingNotes->id) {
                    $clinic = BeanFactory::getBean("TJ_Clinics");
                    $clinic->disable_row_level_security = true;
                    $clinic->retrieve($visitPendingNotes->tj_clinics_tj_visits_1tj_clinics_ida);
                    throw new SugarApiExceptionInvalidParameter('Patient has notes pending at the '.$clinic->name.' clinic. WC/DC call clinic.');
                }
                // Verify patient's balance                
                $patient = BeanFactory::retrieveBean("Contacts", $main_patient_id, array('disable_row_level_security' => true));
                if (floatval($patient->payment_due) > 0.00 || floatval($patient->balance) > 0.00) {
                    throw new SugarApiExceptionInvalidParameter('The patient has a past due balance.');
                }

                if ($patient->plan_status == "Frozen") {
                    throw new SugarApiExceptionInvalidParameter("Patient's plan is currently frozen, can't enter the Waiting Queue.");
                }
                

                $lastVisitID = $patient->lastvisitid_c;

                if ($lastVisitID) {
                    $lastVisitBean = BeanFactory::retrieveBean("TJ_Visits", $lastVisitID, array('disable_row_level_security' => true));
                    $beanTemplate = (array)$lastVisitBean->field_defs;
                    foreach ($beanTemplate as $def) {
                        if (!(isset($def['source']) && $def['source'] == 'non-db') && !empty($def['name']) && in_array($def['name'], $this->copyFields)) {
                            $fieldName = $def['name'];
                            $bean->$fieldName = $lastVisitBean->$fieldName;
                        }
                    }
                    // if(!is_null($lastVisitID)){
                    //     $bean->load_relationship('tj_visits_tj_visits_1');
                    //     $bean->tj_visits_tj_visits_1->add($lastVisitID);//Add Parent Visit
                    // }
                }
                if(!empty($patient->lastvisitid_c)){
                    $bean->charge = "Charge";
                }
                if($patient->first_visit_c){
                    $bean->visit_type = 2;
                }
                if ($patient->load_relationship('contacts_tjc_treatmentplans_1')) {
                    $timedate = new TimeDate();
                    $treatment_end = null;
                    $relatedBeans = $patient->contacts_tjc_treatmentplans_1->getBeans();
                    foreach ($relatedBeans as $beanTreatment) {
                        if ($beanTreatment->active == 1) {
                            //Get Active Plan ID
                            $planID = $beanTreatment->id;
                            $treatment_end = $beanTreatment->end_date;
                        }
                    }
                    //Format plan end_date
                    $treatmentDate = new DateTime($treatment_end);
                    $treatmentDate = $treatmentDate->format('Y-m-d');
                    //Format visit date_entered
                    $beanDate = new DateTime($bean->date_entered);
                    $beanDate = $timedate->asUser($beanDate, $current_user);
                    $beanDate = new DateTime($beanDate);
                    $beanDate = $beanDate->format("Y-m-d");
                    //Set exam type if plan is expired
		            if($beanDate > $treatment_end && !empty($planID)){    
                        $bean->visit_type = 2;
                        $inactivePlan = BeanFactory::retrieveBean('tjc_TreatmentPlans', $planID, array('disable_row_level_security' => true));
                        $inactivePlan->active = 0;
                        $inactivePlan->save();
                    }
                }
    

            }

            // $clinicVisits = $this->getClinicVisitToday();

            // $bean->sortposition = $clinicVisits + 1;
        }
        
        if($current_user->isAdmin() != 1 )
        {
            
            if($bean->fetched_row['status'] != 'Waiting Queue' && in_array($bean->status, ["Waiting Queue"]) && $bean->fetched_row == true)
            {
                throw new SugarApiExceptionInvalidParameter('You cannot move the patient to the Waiting Queue now that the visit has started.');
            }

            if(in_array($bean->fetched_row['status'], ["Cancelled", "Completed"]) && $bean->fetched_row['status'] != $bean->status){
                throw new SugarApiExceptionInvalidParameter("This visit cannot be edited in this way.");
            }

            if($bean->status == "Waiting Queue" && $bean->fetched_row['status'] == "Pending Notes"){
                throw new SugarApiExceptionInvalidParameter("This visit cannot be edited in this way.");
            }

            
            
        }

        if($bean->status == "Waiting Queue" && $bean->status != $bean->fetched_row["status"]) {
            $bean->waiting_queue_time = $bean->date_modified;
        }

        if($arguments['isUpdate']) {
            if($bean->status != 'Forfeited'){
                $isReExam = $this->isReExam($arguments, true, $bean);
                if($isReExam){
                    // $bean->visit_type = 2;
                }
            }/*else{
                 $bean->visit_price = 0;
            }*/
            if($bean->status == "Pending Notes" 
                && $bean->status != $bean->fetched_row["status"] 
                && !empty($bean->waiting_queue_time)
            ) {
                $secords = (strtotime($bean->date_modified) - strtotime($bean->waiting_queue_time));
                $bean->average_wait_time = $secords;
            }

            if($bean->charge == 'Care Card' && $bean->status=='Pending Notes' && ($bean->charge != $bean->fetched_row['charge'])  ){
                throw new SugarApiExceptionInvalidParameter("You can't select 'Care Cards' as a charge option.");
            }

            if($bean->fetched_row['status'] == "Waiting Queue" && $bean->status == "Pending Notes"){
                //Patient info
                $bean->load_relationship('contacts_tj_visits_1');
                $relatedPatient = $bean->contacts_tj_visits_1->get();
                $main_patient_id = $relatedPatient[0];
                $contactUtils = new ContactsUtils;
                $contact = $contactUtils->getContactWithPendingSignature(null, null, null, $main_patient_id); 
                if($contact){
                    if($contact["module"] == "Contacts"){
                        throw new SugarApiExceptionInvalidParameter("This patient cannot be moved to pending until their digital forms are completed.  If you would like to  use paper forms instead and be able to move the patient to pending immediately, please uncheck the Send Forms box on the patient record, and click Save");
                    }
                    // if($contact["module"] == "TJ_PatientRequest"){
                    //     throw new SugarApiExceptionInvalidParameter("This patient cannot be moved to pending until their digital forms are completed.  If you would like to  use paper forms instead and be able to move the patient to pending immediately, please uncheck the Send Forms box on the patient record, and click Save");
                    // }
                    // if($contact["module"] == "TJ_Purchases"){
                    //     throw new SugarApiExceptionInvalidParameter("This patient cannot be moved to pending until their digital forms are completed.  If you would like to  use paper forms instead and be able to move the patient to pending immediately, please uncheck the Send Forms box on the patient record, and click Save");
                    // }
                }
            }

            if($bean->status == "Completed" && $bean->status != $bean->fetched_row["status"] ){

                $bean->load_relationship('users_tj_visits_2');
                $bean->users_tj_visits_2->add($current_user->id);
                $this->updatePurchaseDC($bean);
                $timedate = new TimeDate();

                $bean->load_relationship("tj_visits_tj_visitothers_1");
                $relatedID = $bean->tj_visits_tj_visitothers_1->get();
                $beanOther = BeanFactory::retrieveBean('TJ_VisitOthers', $relatedID[0], array('disable_row_level_security' => true));
                //Visit Info
                $date_modified = date_create($bean->date_modified);
                $beanOther->completiondate_date = $timedate->asDbDate($date_modified);
                $beanOther->completiondate_time = $timedate->asDbTime($date_modified);
                $beanOther->dayofweek = $date_modified->format('N');

                //Patient info
                $bean->load_relationship('contacts_tj_visits_1');
                $relatedPatient = $bean->contacts_tj_visits_1->get();
                $main_patient_id = $relatedPatient[0];
                if (!is_null($main_patient_id)) {
                    $patient = BeanFactory::retrieveBean('Contacts', $main_patient_id, array('disable_row_level_security' => true));
                    $beanOther->previousvisitdate = $patient->lastvisitdate_c;
                    $beanOther->previousvisitplan = $patient->lastvisitpurchaseplan_c;
                    // if($patient->status_c == 'walkin'){
                    //     $beanOther->patientmarketingtype = 'Prospect';                    
                    // }
                    // else{
                    //     $beanOther->patientmarketingtype = 'Customer';                    
                    // }
                    // $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);
                    // if(!is_null($clinic) && ($patient->tj_clinics_contacts_1tj_clinics_ida != $clinic->id)){
                    //     $beanOther->interclinicvisit = true;
                    // }
                    if ($patient->first_visit_c == true) {
                        $patient->first_visit_c = false;
                        $patient->save();
                    }                          
                }                    
                
                //Purchase info
                $purchase = BeanFactory::retrieveBean('TJ_Purchases', $bean->tj_purchases_tj_visits_1tj_purchases_ida, array('disable_row_level_security' => true));
                if(!is_null($purchase)){
                    $beanOther->visitplan = $purchase->producttype;
                }
                $beanOther->save();
            }
        }

        $bean->has_do_not_adjust = TJ_VisitsUtils::hasDoNotAdjust((Array)$bean);
    }

    public function afterSave($bean, $event, $arguments)
    {
        global $current_user,
        $db, $app_list_strings ;
         if(self::$already_ran == true) return;
        self::$already_ran = true;
        //new record
        if (!$arguments['isUpdate']) {
            //attach user clinics
            $bean->load_relationship('contacts_tj_visits_1');
            $relatedPatient = $bean->contacts_tj_visits_1->getBeans();
            $patient = current($relatedPatient);
            // $patient = BeanFactory::retrieveBean("Contacts", $bean->contacts_tj_visits_1contacts_ida, array('disable_row_level_security' => true));
            $patient->load_relationship("contacts_tj_visits_1");

            // $producttype = isset($app_list_strings['plant_type_list'][$patient->producttype_c])? 'Walk-In' : $app_list_strings['plant_type_list'][$patient->producttype_c];	
            
            // $query = 'UPDATE tj_visits_cstm SET plan_c = "'.$producttype.'" WHERE id_c = ' . $GLOBALS['db']->quoted($bean->id);
            // $db->query($query);  

            $visits = $patient->contacts_tj_visits_1->get();
            $objectName = 'TJ_Visits';
            if(!empty($patient->lastvisitid_c)){
                global $dictonary;
                $a = $dictonary[$objectName]['related_calc_fields'];
                unset($dictonary[$objectName]['related_calc_fields']);
                $this->copyLastVisitValues($bean, $patient);
                $dictonary[$objectName]['related_calc_fields'] = $a;
            } 

            if ($arguments['dataChanges']['status']['after'] == 'Pending Notes') {
                $bean->load_relationship('contacts_tj_visits_1');
                $relatedPatient = $bean->contacts_tj_visits_1->getBeans();
                
                if (!empty($relatedPatient)) {
                    $patient = current($relatedPatient);
                    visit_patient::$already_ran = false;
                    $this->linkPatientVisitInfo($patient, $bean);
        
                    if ($patient->first_visit_c == true) {
                        $this->createTask($patient->id, $bean->tj_clinics_tj_visits_1tj_clinics_ida);
                    }

                    $patient = BeanFactory::retrieveBean("Contacts", $bean->contacts_tj_visits_1contacts_ida, array('disable_row_level_security' => true));

                    if($bean->charge!='No Charge Comp'){
                        if ($patient->status_c=="walkin") {
                            $this->checkHomeClinic($patient);
                            
                            $bean->tj_clinics_tj_visits_2tj_clinics_ida = $bean->tj_clinics_tj_visits_1tj_clinics_ida;

                            $paymentType = $this->checkRPV($patient, $bean, true);
                        } else {
                            $this->linkPurchase($patient, $bean);
                            $paymentType = $this->checkRPV($patient, $bean);
                        }
                    }
                    // $contactUtils = new ContactsUtils;
                    // $patient->number_of_visits_c += 1;
                    
                    $bean->load_relationship("tj_visits_tj_visitothers_1");
                    $relatedID = $bean->tj_visits_tj_visitothers_1->get();
                    $beanOther = BeanFactory::retrieveBean('TJ_VisitOthers', $relatedID[0], array('disable_row_level_security' => true));
                    $beanOther->visitpaidtype = $paymentType;
                    $timedate = new TimeDate();
                    $beanDate = new DateTime($bean->date_entered);
                    $beanDate = $timedate->asUser($beanDate, $current_user);
                    $beanDate = new DateTime($beanDate);
                    $beanOther->visit_year = $beanDate->format("Y");
                    $beanOther->visit_month = $beanDate->format("m");
                    $beanOther->visit_day = $beanDate->format("d");
                    $interclinicvisit_value = $beanOther->inter_clinic_visit_fee;
                    $beanOther->inter_clinic_visit_fee = null; 
                    if($paymentType=='RPV'){
                        $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);
                        //if(!is_null($clinic) && ($patient->tj_clinics_contacts_1tj_clinics_ida != $clinic->id)){
                        if($bean->tj_clinics_tj_visits_1tj_clinics_ida != $bean->tj_clinics_tj_visits_2tj_clinics_ida){// Use clinic ids from the visit record.
                            $beanOther->interclinicvisit = true;
                            $beanOther->inter_clinic_visit_fee = $interclinicvisit_value;
                        }
                    }
                    
                    $beanOther->save();
                
                    // $patient->save(); 
                    $pricebook = $this->getClinicWalkin($bean->tj_clinics_tj_visits_1tj_clinics_ida, $patient);
                    if(!empty($pricebook) && !empty($pricebook['id'])){
                        $bean->load_relationship("tj_pricebooks_tj_visits_1");
                        $bean->tj_pricebooks_tj_visits_1->add($pricebook['id']);
                    }   
                    
                }
            }
            
            if (empty($bean->tj_clinics_tj_visits_1tj_clinics_ida)) {
                $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);
                if (!empty($clinic) && $clinic->id) {
                    $clinic->load_relationship("tj_clinics_tj_visits_1");
                    $clinic->tj_clinics_tj_visits_1->add($bean->id);                  
                }
            }
            // TODO relate with a job
            // http://gitlab.arcsona.com/the-joint/the-joint/commit/f4d93f20024dca3cbedcad890b873736b782bb55

            
        }
        if ($arguments['isUpdate']) {
            if (isset($arguments['dataChanges']['status']['after'])) {
                $patient = BeanFactory::retrieveBean("Contacts", $bean->contacts_tj_visits_1contacts_ida, array('disable_row_level_security' => true));
                if ($arguments['isUpdate'] && $arguments['dataChanges']['status']['after'] == 'Pending Notes') {
                    $this->linkPatientVisitInfo($patient, $bean);
        
                    if ($patient->first_visit_c == true) {
                        $this->createTask($patient->id, $bean->tj_clinics_tj_visits_1tj_clinics_ida);
                    }

                    //TODO hot fix retrieveBean TJC-1421
                    $patient = BeanFactory::retrieveBean("Contacts", $bean->contacts_tj_visits_1contacts_ida, array('disable_row_level_security' => true));

                    if($bean->charge!='No Charge Comp'){
                        if ($patient->status_c=="walkin") {
                            $this->checkHomeClinic($patient);
                            $bean->tj_clinics_tj_visits_2tj_clinics_ida = $bean->tj_clinics_tj_visits_1tj_clinics_ida;
                            $paymentType = $this->checkRPV($patient, $bean, true);
                        } else {
                            $this->linkPurchase($patient, $bean);
                            $paymentType = $this->checkRPV($patient, $bean);
                        }
                    }
                    $patient = BeanFactory::retrieveBean("Contacts", $bean->contacts_tj_visits_1contacts_ida, array('disable_row_level_security' => true));

                    // //When a visit is completed
                    $contactUtils = new ContactsUtils;
                    //$patient->tvtm_c = count($contactUtils->getVisitsCompletedPending($patient));


                    $bean->load_relationship("tj_visits_tj_visitothers_1");
                    //TJC-1421
                    // $patient->number_of_visits_c += 1;                    
                    $relatedID = $bean->tj_visits_tj_visitothers_1->get();
                    $beanOther = BeanFactory::retrieveBean('TJ_VisitOthers', $relatedID[0], array('disable_row_level_security' => true));
                    $beanOther->visitpaidtype = $paymentType;
                    
                    $timedate = new TimeDate();
                    $beanDate = new DateTime($bean->date_entered);
                    $beanDate = $timedate->asUser($beanDate, $current_user);
                    $beanDate = new DateTime($beanDate);
                    $beanOther->visit_year = $beanDate->format("Y");
                    $beanOther->visit_month = $beanDate->format("m");
                    $beanOther->visit_day = $beanDate->format("d");
                    
                    $interclinicvisit_value = $beanOther->inter_clinic_visit_fee;
                    $beanOther->inter_clinic_visit_fee = null; 
                    if($paymentType=='RPV'){
                        $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);
                        // if(!is_null($clinic) && ($patient->tj_clinics_contacts_1tj_clinics_ida != $clinic->id)){
                        if($bean->tj_clinics_tj_visits_1tj_clinics_ida != $bean->tj_clinics_tj_visits_2tj_clinics_ida){
                            $beanOther->interclinicvisit = true;
                            $beanOther->inter_clinic_visit_fee = $interclinicvisit_value;
                        }
                    }
                    
                    $beanOther->save();
                    // $patient->save();
                    $bean->save();     
                    
                    $pricebook = $this->getClinicWalkin($bean->tj_clinics_tj_visits_1tj_clinics_ida, $patient);
                    if(!empty($pricebook) && !empty($pricebook['id'])){
                        $bean->load_relationship("tj_pricebooks_tj_visits_1");
                        $bean->tj_pricebooks_tj_visits_1->add($pricebook['id']);
                    }  
                }
            }
        }
        if ($arguments['isUpdate']) {
            if($bean->status == 'Pending Notes' || $bean->status == 'Completed'){
                $patient = BeanFactory::retrieveBean("Contacts", $bean->contacts_tj_visits_1contacts_ida, array('disable_row_level_security' => true));
                $this->linkActivePlan($patient, $bean);
                $this->addGlobalTeam($bean);
            }

            // If visit is saved in backoffice (bo_saved field == true), set users_tj_visits_2 relationship
            if(
                $bean->status == "Pending Notes" &&
                $arguments['dataChanges']['saved_bo']['before'] == false 
                && $arguments['dataChanges']['saved_bo']['after'] == true 
                && $_SESSION['platform'] == "backoffice"){
                
                $bean->load_relationship('users_tj_visits_2');
                $bean->users_tj_visits_2->add($current_user->id);
            }
        }
        if ($arguments['isUpdate']) {
            if (isset($arguments['dataChanges']['status']['after'])) {
                $patient = BeanFactory::retrieveBean("Contacts", $bean->contacts_tj_visits_1contacts_ida, array('disable_row_level_security' => true));
                if ($arguments['isUpdate'] && $arguments['dataChanges']['status']['after'] == 'Completed') {   
                    $this->updateLastVisitInfo($patient, $bean);
                    $this->linkPatientVisitInfo($patient, $bean);
                    // $this->addGlobalTeam($bean);
                    //$this->completeOpenWCMTask($patient->id);
                    $patient->save();

                    if ($patient->load_relationship('contacts_tjc_treatmentplans_1')) {

                        //Fetch related beans
                        $relatedBeans = $patient->contacts_tjc_treatmentplans_1->getBeans();
                        foreach ($relatedBeans as $beanTreatment) {
                            if ($beanTreatment->active == 1) {
                                if($beanTreatment->load_relationship('tjc_treatmentplans_tj_visits_1')){
                                    $relatedVisits = $beanTreatment->tjc_treatmentplans_tj_visits_1->getBeans();
                                    $bean = BeanFactory::retrieveBean("tjc_TreatmentPlans", $beanTreatment->id, array('disable_row_level_security' => true));                                    
                                    $bean->treatment_visits = count($relatedVisits);
                                    $bean->save();
                                }
                            }
                        }
                    }
                    $this->completeOpenWCMTask($patient->id);
                }
            }
        }

        // if ($arguments['isUpdate'] && ($bean->status == "Completed" || $bean->status == 'Pending Notes')) {
        //      $this->addGlobalTeam($bean);
        // }

        
    }

    public function afterRelationshipAdd($bean, $event, $arguments){
        global $current_user, $app_list_strings;
        if($arguments['related_module'] === 'TJ_Clinics'){
            
            if($bean->status != 'Forfeited'){
                if($_SESSION['platform'] == "backoffice" || $_SESSION['platform'] == "base"){
                    $clinicVisits = $this->getClinicVisitToday();
                    $bean->sortposition = $clinicVisits + 1;
                }
            }
        }
        if($arguments['related_module'] === 'Contacts')
        {
            $patient = BeanFactory::retrieveBean($arguments['related_module'], $arguments['related_id'], array('disable_row_level_security' => true));
            $currentClinic = $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);
            if($patient->producttype_c == "Walk In"){
                $patient->load_relationship("tj_clinics_contacts_1");
                $patient->tj_clinics_contacts_1->add($currentClinic);                
            }
            // TJC-1808 fix retrive tj_clinics_contacts_1
            $patient->retrieve($arguments['related_id']);
            if(!empty($patient->tj_clinics_contacts_1tj_clinics_ida)){
                $clinic = BeanFactory::retrieveBean("TJ_Clinics", $patient->tj_clinics_contacts_1tj_clinics_ida, array('disable_row_level_security' => true));
                if ($clinic->id) {
                    $clinic->load_relationship("tj_clinics_tj_visits_2");
                    $clinic->tj_clinics_tj_visits_2->add($bean);
                }
                else{
                    $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);
                    if (!empty($clinic) && $clinic->id) {
                        $clinic->load_relationship("tj_clinics_tj_visits_2");
                        $clinic->tj_clinics_tj_visits_2->add($bean);
                    }
                }
            }
            else{
                $clinic = $currentClinic;
                if (!empty($clinic) && $clinic->id) {
                    $clinic->load_relationship("tj_clinics_tj_visits_2");
                    $clinic->tj_clinics_tj_visits_2->add($bean);
                }
            }

            $visitOther = BeanFactory::newBean('TJ_VisitOthers');
            $visitOther->name = $bean->name;
            $visitOther->assigned_user_id =$bean->assigned_user_id;
            $visitOther->new_with_id = true;
            $visitOther->id = $bean->id;
            //if(is_null($patient->lastvisitid_c) && !$patient->migrated_c){
            if($patient->first_visit_c && !$patient->migrated_c){
                $visitOther->patientvisittype = 'New';
            }else{
                $visitOther->patientvisittype = 'Existing';
            }    
            if($patient->status_c == 'walkin'){
                $visitOther->patientmarketingtype = 'Prospect';                    
            }
            else{
                $visitOther->patientmarketingtype = 'Customer';                    
            }        
            // $visitOther->team_set_id = $bean->team_set_id;
            // $visitOther->load_relationship('teams');
            // $visitOther->teams->add(
            //     array(
            //         $current_user->team_id,1
            //     )
            // );
            $visitOther->save();
            $bean->load_relationship("tj_visits_tj_visitothers_1");
            $bean->tj_visits_tj_visitothers_1->add($visitOther);

            

            if(!empty($patient->contacts_tj_intakeforms_1tj_intakeforms_idb)){
                $bean->load_relationship("tj_intakeforms_tj_visits_1");
                $bean->tj_intakeforms_tj_visits_1->add($patient->contacts_tj_intakeforms_1tj_intakeforms_idb);
            }
        }

        if($arguments['related_module'] === 'TJ_Purchases'){
            $purchase = BeanFactory::retrieveBean($arguments['related_module'], $arguments['related_id'], array('disable_row_level_security' => true));
            $bean->plan_c = $app_list_strings['plant_type_list'][$purchase->producttype];
        }        
    }

    public function getPatientID($bean)
    {
        $main_patient_id = null;
        //Obtain the id of the actual patient
        if ($bean->load_relationship('contacts_tj_visits_1')) {
            $relatedPatient = $bean->contacts_tj_visits_1->get();
            if (count($relatedPatient)) {
                $main_patient_id = $relatedPatient[0];
            }
        }
        return $main_patient_id;
    }

    public function copyLastVisitValues($bean, $patientBean)
    {        
        global $sugar_config, $current_user;        
        $lastVisitID = $patientBean->lastvisitid_c;

        if ($lastVisitID) {            
            //copy related links
            //Complains            
            $relatedComplains = TJ_VisitsUtils::getComplaints($lastVisitID);                        
            
            
            if(count($relatedComplains)){
                foreach ($relatedComplains as $complainBean) {
                    $complainData = (Array)$complainBean;                                                
                    // $complainData['assigned_user_id'] = $current_user->id;                                      
                    $newComplain = BeanFactory::newBean('TJ_Complaints');   
                    $newComplain->frequency = $complainData['frequency'];                    
                    $newComplain->complaint = $complainData['complaint'];
                    $newComplain->status = $complainData['status'];
                    $newComplain->description = $complainData['description'];
		            $newComplain->same_better_worse  = isset($complainData['same_better_worse ']) && $complainData['same_better_worse '] ? $complainData['same_better_worse '] : '';
                    $newComplain->pain_level  = $complainData['pain_level'];
                    $newComplain->assigned_user_id = $bean->assigned_user_id;
                    $newComplain->name = "Complain " . $bean->name;
                    $newComplain->in_save =  true;
                    $sugar_config['disable_related_calc_fields'] = true;
                    $team_id = 1;
                    // $newComplain->load_relationship("teams");
                    $newComplain->team_id = 1;
                    $newComplain->team_set_id = 1;
                    // $newComplain->teams->replace([$team_id]);
                    $newComplain->save(false);
                    $sugar_config['disable_related_calc_fields'] = false;

                    $patientBean->load_relationship('contacts_tj_complaints_1');
                    $patientBean->contacts_tj_complaints_1->add($newComplain);

                    $bean->load_relationship('tj_visits_tj_complaints_1');
                    $bean->tj_visits_tj_complaints_1->add($newComplain);
                }
            }
        }
    }

    public function checkRPV(&$patientBean, &$visitBean, $isWalkIn=false)
    {
        //, if (patient RPVs > 0 AND visit == Charge )then RPVs = RPVs -1
        global $current_user;
        $balanceAmount = 0;
        $paymentType = 'RPV';

        // If in home clinic and care cards then deduct care card and change visit cost to 0
        // else if RPVs gt 0 then deduct and RPV 
        // else add intro or walking to balance        
        $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);
        // if(!is_null($clinic) && ($patientBean->tj_clinics_contacts_1tj_clinics_ida == $clinic->id) && $patientBean->carecard_c){
        //     $patientBean->carecard_c -= 1;    
        //     // $patientBean->tvtm_c += 1;     
        //     $visitBean->visit_price = 0;
        //     $paymentType = 'PPV';
        // }
        // else 
        if($patientBean->rpv_c || $patientBean->status_c == "Plan" || $patientBean->status_c == "Package"){
            // $patientBean->tvtm_c += 1;            
            if ($visitBean->tj_purchases_tj_visits_1tj_purchases_ida) {
                $purchaseBean = BeanFactory::retrieveBean("TJ_Purchases", $visitBean->tj_purchases_tj_visits_1tj_purchases_ida, array('disable_row_level_security' => true));
            } else {
                $purchases = $this->getInactivePlanPurchases($patientBean->id);
                if (count($purchases)) {
                    foreach ($purchases as $purchaseArray) {
                        $purchaseBean = BeanFactory::retrieveBean("TJ_Purchases", $purchaseArray['id'], array('disable_row_level_security' => true));
                        break;
                    }
                }
            } 
            //TODO:1421 need to fix for carecards
            if(!is_null($clinic) && ($patientBean->tj_clinics_contacts_1tj_clinics_ida == $clinic->id) && $purchaseBean->carecards)
            {
                $purchaseBean->carecards -= 1;
                $paymentType = 'RPV';
                $visitBean->visit_price = 0;
                UpdatePurchaseVisitsLeftCurrCycle::$already_ran = false;
                $purchaseBean->save();
                $paymentType = 'PPV';
            }
            else if($patientBean->rpv_c == 0  && $patientBean->status_c == "Plan"){
                $balanceAmount = $purchaseBean->overvisitcost;
                $visitBean->visit_price = $balanceAmount;
                $patientBean->tvtm_c += 1; 
                $paymentType = 'Overage';
            } else {
                if ($purchaseBean->visitsleftcurrcycle>0) {                    
                    $purchaseBean->visitsleftcurrcycle -= 1;    
                    if ($purchaseBean->visitsleftcurrcycle==0 && $patientBean->status_c == "Package") {
                        $purchaseBean->status = "Inactive";
                        $purchaseBean->purchaseactive = false;
                        $activePurchase = TJ_PurchasesUtils::getActivePlanPackages($patientBean->id);
                        if (count($activePurchase)>1) {
                            $patientBean->producttype_c = $activePurchase['1']['producttype'];
                        }
                    }
                    $purchaseBean->save();
                    $paymentType = 'RPV';
                }
                 //interclinic
                // if($visitBean->tj_clinics_tj_visits_1tj_clinics_ida != $visitBean->tj_clinics_tj_visits_2tj_clinics_ida){
                //    $visitBean->different_clinic = true;
                // }
            } 
        }
        else{
            $pricebook = $this->getClinicWalkin($visitBean->tj_clinics_tj_visits_1tj_clinics_ida, $patientBean);
            $balanceAmount = $pricebook['cost_c'];
            $visitBean->visit_price = $balanceAmount;
            // $visitBean->tj_pricebooks_tj_visits_1tj_pricebooks_ida = $pricebook['id'];
            $paymentType = 'PPV';
        }
       //TJC-1421 save changes
        if($balanceAmount > 0 ){
            $patientBean->payment_due += $balanceAmount;
            
            $patientBean->save();
        }
        //Interclinic visit
        if($paymentType != 'PPV'){
            if($visitBean->tj_clinics_tj_visits_1tj_clinics_ida != $visitBean->tj_clinics_tj_visits_2tj_clinics_ida){
                $visitBean->different_clinic = true;
            }
        }
       return $paymentType;
    }
    
    public function linkPurchase($patientBean, &$visitBean)
    {
        $relatedPurchases = $this->getActivePurchases($patientBean->id);
                
        if (count($relatedPurchases)) {
            foreach ($relatedPurchases as $purchaseBean) {                
                $visitBean->tj_purchases_tj_visits_1tj_purchases_ida = $purchaseBean['id'];
                $visitBean->visit_price = $purchaseBean['visit_price'];
                $visitBean->monthlyamount = $purchaseBean['monthlyamount'];
                break;
            }
        }
    }

    public function linkActivePlan($patientBean, &$visitBean)
    {
        if(empty($patientBean->id))return false;
        //If relationship is loaded
        if ($patientBean->load_relationship('contacts_tjc_treatmentplans_1')) {
            //Fetch related beans
            $relatedBeans = $patientBean->contacts_tjc_treatmentplans_1->getBeans();
            foreach ($relatedBeans as $beanTreatment) {
                if ($beanTreatment->active == 1) {
                    $visitBean->load_relationship('tjc_treatmentplans_tj_visits_1');
                    $visitBean->tjc_treatmentplans_tj_visits_1->add($beanTreatment->id);
                    
                    break;
                }
            }
        }
    }

    public function addGlobalTeam(&$visitBean)
    {
        $team_id = 1;
        $visitBean->load_relationship("teams");
        $visitBean->team_id = 1;
        $visitBean->teams->setSaved(false);
        $visitBean->teams->replace([$team_id]);
        

         
        // global $current_user;
        // $current_user->load_relationship("teams");
        // $current_user->teams->remove([$team_id]);
        // $current_user->save();
        // $current_user->retrieve();
    }

    public function createTask($patientID, $clinicID)
    {
        global $current_user;
        $user_preferences = new UserPreference($current_user);  
        $userTZ = $user_preferences->getPreference('timezone','global');
        $userTZ = !is_null($userTZ) ? $userTZ : 'America/Phoenix';
        $today = new SugarDateTime("now", new DateTimeZone($userTZ));
        $dueDate = clone $today;
        $dueDate->modify("+5 day");
        $p = new Contact();
        $p->retrieve($patientID);
        $p_email = $p->emailAddress->getPrimaryAddress($p);

        $teamID = TJ_ClinicsUtils::getClinicTeamId($clinicID);
        $newTask = BeanFactory::newBean('Tasks');
        $newTask->description = "3-day Follow-up";
        $newTask->priority = "High";
        $newTask->task_type_c = "3 day follow up";
        $newTask->parent_type= "Contacts";
        $newTask->parent_id = $patientID;
        $newTask->contact_email = $p_email;
        $newTask->contact_id = $patientID;
        $newTask->name = "3-day Follow-up";
        $newTask->team_id = $teamID;
        $newTask->date_due = $dueDate->format('Y-m-d h:m:s');
        $newTask->save();
    }

    // public function checkBalance($visitBean, $planPatient=false)
    public function getClinicWalkinPrice($clinicVisitID, $patientBean)
    {
        $balanceAmount = 0;
        $customCurrentUserApi = new CustomCurrentUserApi;
        $pricebooks = $customCurrentUserApi->getPricebooks(false);
        foreach ($pricebooks as $pricebook) {
            if($pricebook['type'] == 'walkin' && $pricebook['balance_visit'] == '1'){
                if($patientBean->first_visit_c){
                    if($pricebook['first_visit']){
                        $balanceAmount = $pricebook['cost_c'];    
                        break;
                    }
                }
                else{
                    if(!$pricebook['first_visit']){
                        $balanceAmount = $pricebook['cost_c'];    
                        break;
                    }       
                }
            }            
        }
        return $balanceAmount;
    }

    public function getClinicWalkin($clinicVisitID, $patientBean)
    {
        $customCurrentUserApi = new CustomCurrentUserApi;
        $pricebooks = $customCurrentUserApi->getPricebooks(false);
        $walkinPricebook = null;
        foreach ($pricebooks as $pricebook) {
            if($pricebook['type'] == 'walkin' && $pricebook['balance_visit'] == '1'){
                if($patientBean->first_visit_c){
                    if($pricebook['first_visit']){
                        $walkinPricebook = $pricebook;
                        break;
                    }
                }
                else{
                    if(!$pricebook['first_visit']){
                        $walkinPricebook = $pricebook;
                        break;
                    }       
                }
            }            
        }
        return $walkinPricebook;
    }


    public function getActivePurchases($patientID)
    {
        $dbInstance =  DBManagerFactory::getInstance('reports');
        $SugarQuery = new SugarQuery($dbInstance);
        $SugarQuery->select(array('*'));
        $SugarQuery->from(BeanFactory::newBean('TJ_Purchases'));
        $SugarQuery->where()
            ->equals('contacts_tj_purchases_1contacts_ida', $patientID)
            ->equals('purchaseactive', '1')
            ->notEquals('purchasetype', 'Discount');
        $SugarQuery->orderBy('date_entered', 'ASC');
        $purchases = $SugarQuery->execute();
        return $purchases;
    }

    public function getInactivePlanPurchases($patientID)
    {
        $dbInstance =  DBManagerFactory::getInstance('reports');
        $SugarQuery = new SugarQuery($dbInstance);
        $SugarQuery->select(array('*'));
        $SugarQuery->from(BeanFactory::newBean('TJ_Purchases'));
        $SugarQuery->where()
            ->equals('contacts_tj_purchases_1contacts_ida', $patientID)
            ->equals('purchaseactive', '0')
            ->equals('purchasetype', 'Plan');
        $SugarQuery->orderBy('date_entered', 'DESC');
        $purchases = $SugarQuery->execute();
        return $purchases;
    }

    public function getActivePurchaseItems($purchaseID)
    {
        $dbInstance =  DBManagerFactory::getInstance('reports');
        $SugarQuery = new SugarQuery($dbInstance);
        $SugarQuery->select(array('*'));
        $SugarQuery->from(BeanFactory::newBean('TJ_Purchase_Items'));
        $SugarQuery->where()
            ->equals('tj_purchases_tj_purchase_items_1tj_purchases_ida', $purchaseID)
            ->equals('status', 'Active');
        $SugarQuery->orderBy('date_entered', 'ASC');
        $purchaseItems = $SugarQuery->execute();
        return $purchaseItems;
    }

    public function getLastPurchaseItemCost($purchaseID)
    {
        $dbInstance =  DBManagerFactory::getInstance('reports');
        $SugarQuery = new SugarQuery($dbInstance);
        $SugarQuery->select(array('overvisitcost'));
        $SugarQuery->from(BeanFactory::newBean('TJ_Purchase_Items'));
        $SugarQuery->where()
            ->equals('tj_purchases_tj_purchase_items_1tj_purchases_ida', $purchaseID);
        $SugarQuery->orderBy('date_entered', 'DESC');
        $purchaseItems = $SugarQuery->getOne();

        return $purchaseItems;
    }

    public function getClinicVisitToday()
    {
        global $current_user;
        $visits = [];
        $dbInstance =  DBManagerFactory::getInstance('reports');
        $SugarQuery = new SugarQuery($dbInstance);
        // $SugarQuery->select(array('*'));
        $SugarQuery->select()->fieldRaw(
            'MAX(sortposition) sortposition'
        );

        $SugarQuery->from(BeanFactory::newBean('TJ_Visits'));
        // $SugarQuery->where()->queryAnd()->equals('tj_clinics_tj_visits_1tj_clinics_ida', $current_user->team_id)
        //Get clinic based on team id
        $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);

        //Get visits for today, with status of Waiting queue
        $SugarQuery->Where()->queryAnd()->equals('tj_clinics_tj_visits_1tj_clinics_ida', $clinic->id)
                   ->dateRange('date_entered', 'today')
                   ->equals('status', 'Waiting Queue');
        $visits = $SugarQuery->getOne();
        return $visits;
    }


    
    public function linkPatientVisitInfo(&$beanPatient, &$bean)
    {

            //loop prevention check
        if (!isset($bean->ignore_update_c) || $bean->ignore_update_c === false) {
            $user_id =  $bean->assigned_user_id;
            $patient_id = null;
            $clinic_patient_id = null;
            $clinic_user_id = null;

            if ($bean->load_relationship('tj_clinics_tj_visits_1')) {
                $relatedClinic = $bean->tj_clinics_tj_visits_1->get();
                if ($relatedClinic) {
                    $clinic_user_id = $relatedClinic[0];
                }
            }

            if ($bean->load_relationship('tj_clinics_tj_visits_2')) {
                $relatedClinic = $bean->tj_clinics_tj_visits_2->get();
                if ($relatedClinic) {
                    $clinic_patient_id = $relatedClinic[0];
                }
            }

            // if ($clinic_patient_id && $clinic_user_id && ($clinic_patient_id != $clinic_user_id)) {
            //     $bean->different_clinic = true;
            // }
            //Update patients last visit information
            if ($beanPatient) {
                $beanPatient->load_relationship('tj_clinics_contacts_2');
                if ($clinic_user_id) {
                    $beanPatient->tj_clinics_contacts_2->add($clinic_user_id);
                }
                $beanPatient->load_relationship('users_contacts_1');
                $beanPatient->users_contacts_1->add($user_id);

                // $today = date("Y-m-d");
                // $beanPatient->lastvisitdate_c = $today;
                // $beanPatient->date_of_visit_c = $today;
                // $beanPatient->lastvisitid_c = $bean->id;
                // if(empty($beanPatient->firstvisitdate_c)){
                //     $beanPatient->firstvisitdate_c = $today;
                // }
            }
        }
    }

    public function updateLastVisitInfo(&$beanPatient, &$bean){
        global $current_user;
        if ($beanPatient) {
            // Date of visit creation
            $creation_date = new DateTime($bean->date_entered);
            $creation_date = $creation_date->format('Y-m-d');
            
            // Today's date
            // $timedate = new TimeDate($current_user);
            // $today = $timedate->getNow(true);
            $userTZ = $current_user->user_preferences['global']['timezone'];
            $userTZ = !is_null($userTZ) ? $userTZ : 'America/Phoenix';
            $today = new SugarDateTime("now", new DateTimeZone($userTZ));
            $today = $today->format("Y-m-d");
            
            // $timedate = new TimeDate($current_user);
            // $today = $timedate->getNow(true);
            // $today = $today->format("Y-m-d");
	        $beanPatient->number_of_visits_c = ($beanPatient->number_of_visits_c ?: 0) + 1;
            if($today > $creation_date){
                $beanPatient->lastvisitdate_c = $creation_date;    
            }else{
                $beanPatient->lastvisitdate_c = $today;   
            }
            
            $beanPatient->date_of_visit_c = $today;
            $beanPatient->lastvisitid_c = $bean->id;
            $beanPatient->last_dc_name = $current_user->full_name;
            $beanPatient->lastdcid = $current_user->id; 
            $bean->assigned_user_id = $current_user->id;
            if(empty($beanPatient->firstvisitdate_c)){
                $beanPatient->firstvisitdate_c = $today;
            }
            $purchase = BeanFactory::retrieveBean('TJ_Purchases', $bean->tj_purchases_tj_visits_1tj_purchases_ida, array('disable_row_level_security' => true));
            if(!is_null($purchase)){
                $beanPatient->lastvisitpurchaseplan_c = $purchase->producttype;
            }            
        }
    }

    public function createFirstPurchase($visit, $patient)
    {
        $purchase = BeanFactory::newBean("TJ_Purchases");
        $purchase->name = "First Purchase";
        // $purchase->contacts_tj_purchases_1contacts_ida = $patient->id;
        $purchase->save(false);
        $patient->load_relationship("contacts_tj_purchases_1");
        $patient->contacts_tj_purchases_1->add($purchase);

        $purchaseItemsUtils = new TJ_PriceBooksUtils;
        $introductoryPriceBook = $purchaseItemsUtils->getIntroductoryByCurrentClinic();
        if ($introductoryPriceBook && $introductoryPriceBook->id) {
            $purchaseItem = BeanFactory::newBean("TJ_Purchase_Items");
            $purchaseItem->save(false);

            $introductoryPriceBook->load_relationship("tj_pricebooks_tj_purchase_items_1");
            $introductoryPriceBook->tj_pricebooks_tj_purchase_items_1->add($purchaseItem);
            
            $purchase->load_relationship("tj_purchases_tj_purchase_items_1");
            $purchase->tj_purchases_tj_purchase_items_1->add($purchaseItem);
        }
    }
    
    public function createWalkinPurchase($patient)
    {
        $purchase = BeanFactory::newBean("TJ_Purchases");
        $purchase->name = "Walk-in Purchase";
        $purchase->save(false);
        $patient->load_relationship("contacts_tj_purchases_1");
        $patient->contacts_tj_purchases_1->add($purchase);

        $purchaseItemsUtils = new TJ_PriceBooksUtils;
        $walkinPriceBook = $purchaseItemsUtils->getDefaultWalkin();
        
        if ($walkinPriceBook && $walkinPriceBook->id) {
            $purchaseItem = BeanFactory::newBean("TJ_Purchase_Items");
            $purchaseItem->save(false);

            $walkinPriceBook->load_relationship("tj_pricebooks_tj_purchase_items_1");
            $walkinPriceBook->tj_pricebooks_tj_purchase_items_1->add($purchaseItem);
            
            $purchase->load_relationship("tj_purchases_tj_purchase_items_1");
            $purchase->tj_purchases_tj_purchase_items_1->add($purchaseItem);
        }
    }

    public function getUpdateFields(){
        $fields = array(
            //Neurological tab
            //Sensory
            "sen_c4l", "sen_c4r", "sen_c5l", "sen_c5r",
            "sen_c6l", "sen_c6r", "sen_c7l", "sen_c7r",
            "sen_c8l", "sen_c8r", "sen_t1l", "sen_t1r",
            "sen_l4l", "sen_l4r", "sen_l5l", "sen_l5r", "sen_s1l", "sen_s1r",
            //Romberg
            "rombergtl_c", "rombergtr_c",
            //Reflexes
            "bicepl", "bicepr", "branchil", "branchir",
            "tricepl", "tricepr", "patellar_l", "patellar_r", "achill", "achilr",
            //Motor upper
            "mus_c5l", "mus_c5r", "mus_c6l", "mus_c6r",
            "mus_c7l", "mus_c7r", "mus_c8l", "mus_c8r", "mus_t1l", "mus_t1r",
            //Motor lower
            "mus_l2l_c", "mus_l2r_c", "mus_l3l_c", "mus_l3r_c",
            "mus_l4l", "mus_l4r", "mus_l5l", "mus_l5r",
            "mus_s1l", "mus_s1r", "heell", "heelr", "toel", "toer",

            //Othopedic tab
            //Cervical
            "maxcoml", "maxcomr", "shldrdeprl", "shldrdeprr",
            "bakodyl", "bakodyr", "jackcoml", "jackcomr", "valsaval",
            //Thoracic
            "adaml", "adamr", "schepl", "schepr",
            //Lumbar
            "slrl", "slrr", "bragl", "bragr",
            "kempl", "kempr", "elysl", "elysr",
            "goldl", "goldr",
            //Sacroiliac/Hip
            "anvill_c", "anvilr_c", "hibsl", "hibsr",
            "fabrel", "fabrer", "yeaomanl", "yeaomanr", "trendl", "trendr",
            //Shoulder
            "neersl_c", "neersr_c", "suprasl_c", "suprasr_c",
            "infrasl_c", "infrasr_c", "extrotl_c", "extrotr_c",
            "dropal_c", "dropar_c", "bellpl_c", "bellpr_c",
            "kimtl_c", "kimtr_c", "jerktl_c", "jerktr_c",
            "actcompl_c", "actcompr_c", "apptl_c", "apptr_c",
            //Elbow
            "cozenl_c", "cozenr_c", "golfel_c", "golfer_c",
            //Wrist
            "phatl_c", "phatr_c", "tintl_c", "tintr_c",
            "tintel_c", "tinter_c", "finktl_c", "finktr_c", "wattl_c", "wattr_c",
            //Knee
            "antdtl_c", "antdtr_c", "postdltl_c", "postdltr_c",
            "medcoll_c", "medcolr_c", "extcoll_c", "extcolr_c",
            "mcmtl_c", "mcmtr_c", "obertl_c", "obertr_c", "patgtl_c", "patgtr_c",
            //Ankle
            "postdtl_c", "postdtr_c", "latstl_c", "latstr_c",
            //Other

            //ROM tab
            "cflexpain", "cflexrest", "cextpain", "cextrest",
            "cflexlpain", "cflexlrest", "cflexrpain", "cflexrrest",
            "crotlpain", "crotlrest", "crotrpain", "crotrrestr",
            //lumbar
            "lflexpain", "lflexrestr", "lextpain", "lextrestr",
            "lflexlpain", "lflexlrestr", "lflexrpain", "lflexrrestr",
            "lrotlpain", "lrotlrestr", "lrotrpain", "lrotrrestr",
            //Upper
            "tmjl_ran_c", "tmjr_ran_c", "shouldrl_ran", "shouldrr_ran",
            "elbowl_ran", "elbowr_ran", "wristl_ran", "wristr_ran",
            "scl_ran_c", "scr_ran_c", "acl_ran_c", "acr_ran_c", "ribsl_ran_c", "ribsr_ran_c",
            //Lower
            "hipl_ran", "hipr_ran", "kneel_ran", "kneer_ran", "anklel_ran", "ankler_ran",
            //Upper Painful
            "tmjl_pal_c", "tmjr_pal_c", "shouldrl_pal", "shouldrr_pal",
            "elbowl_pal", "elbowr_pal", "wristl_pal", "wristr_pal",
            "scl_pal_c", "scr_pal_c", "acl_pal_c", "acr_pal_c", "ribsl_pal_c", "ribsr_pal_c",
            //Lower Painful
            "hipl_pal", "hipr_pal", "kneel_pal", "kneer_pal", "anklel_pal", "ankler_pal",
            //cervical
            "cflexnorm", //"cflexpain", "cflexrest",
            "cextnorm", //"cextpain", "cextrest",
            "cflexlnorm", //"cflexlpain", "cflexlrest",
            "cflexrnorm", //"cflexrpain", "cflexrrest",
            "crotlnorm",// "crotlpain", "crotlrest",
            "crotrnorm", //"crotrpain", "crotrrestr",
            //lumbar
            "lflexnorm", //"lflexpain", "lflexrestr",
            "lextnorm", //"lextpain", "lextrestr",
            "lflexlnorm", //"lflexlpain", "lflexlrestr",
            "lflexrnorm", //"lflexrpain", "lflexrrestr",
            "lrotlnorm", //"lrotlpain", "lrotlrestr",
            "lrotrnorm", //"lrotrpain", "lrotrrestr",
        );

        return $fields;
    }

    public function isReExam($dataChanges, $beforeSave = false, $bean = false){
        $isReExam = false;
        $changeValues = $this->getUpdateFields();
        $beanArray = (Array)$bean;
        foreach ($dataChanges as $key => $value) {
            if($beforeSave){
                if(isset($beanArray[$key]) && $beanArray[$key] != $bean->fetched_row[$key]){
                    $isReExam = true;
                    break;
                }
            }
            else{
               if(in_array($key, $changeValues)){
                    $isReExam = true;
                    break;
                } 
            }
        }

        return $isReExam;
    }

    public function checkHomeClinic(&$patient)
    {
        global $current_user;
        $clinic = TJ_ClinicsUtils::getClinicByTeamId($current_user->team_id);        

        if (!empty($clinic) && $clinic->id) {
            if($patient->tj_clinics_contacts_1tj_clinics_ida != $clinic->id){
                $patient->tj_clinics_contacts_1tj_clinics_ida = $clinic->id;
            }
        }        
    }

    public function updatePurchaseDC($bean){
        global $current_user;
     
        $user_preferences = new UserPreference($current_user);  
        $userTZ = $user_preferences->getPreference('timezone','global');
        $userTZ = !is_null($userTZ) ? $userTZ : 'America/Phoenix';

        // Load related purchases
        $bean->load_relationship('tj_purchases_tj_visits_1');
        $relatedPurchases = $bean->tj_purchases_tj_visits_1->getBeans();
        // If related purchase is found
        if (count($relatedPurchases)) {
            // $relatedPurchases = current($relatedPurchases);
            foreach ($relatedPurchases as $purchaseBean) {
                
                $purchaseBean = BeanFactory::retrieveBean("TJ_Purchases", $purchaseBean->id, array('disable_row_level_security' => false ));

                // if($purchaseBean->purchasetype == "Plan" || $purchaseBean->purchasetype == "Package"){
                $purchaseBean->load_relationship("tj_purchases_tj_visits_1");
                // performance_axis - get only id
                $purchaseRelatedVisits = $purchaseBean->tj_purchases_tj_visits_1->get();

                
                if(count($purchaseRelatedVisits) == 1){

                    // $user_preferences = new UserPreference($current_user);  
                    // $userTZ = $user_preferences->getPreference('timezone','global');
                    // $userTZ = !is_null($userTZ) ? $userTZ : 'America/Phoenix';
                    
                    $visitDate = new DateTime($bean->date_entered);
                    $visitDate->setTimezone(new DateTimeZone($userTZ));
                    // $visitDate = $visitDate->format("Y/m/d H:i:s");
                    $visitDate = $visitDate->format('Y-m-d');

                    $purchaseDate = new DateTime($purchaseBean->date_entered);
                    $purchaseDate->setTimezone(new DateTimeZone($userTZ));
                    // $purchaseDate = $purchaseDate->format("Y/m/d H:i:s");
                    $purchaseDate = $purchaseDate->format('Y-m-d');
                    
                    if($bean->tj_clinics_tj_visits_1tj_clinics_ida == $purchaseBean->tj_clinics_tj_purchases_1tj_clinics_ida){
                        if($purchaseDate == $visitDate){

                            $purchaseBean->load_relationship('users_tj_purchases_2');
                            $purchase_user = $purchaseBean->users_tj_purchases_2->get();
                            if(!count($purchase_user)){
 
                                $purchaseBean->load_relationship('users_tj_purchases_2');
                                $purchaseBean->users_tj_purchases_2->add($current_user->id);
                            }
                            
                        }
                    }
                    
                }
                // }
            }

        }
        //TJC 1704 Same patient, same visit, purchased Initial Visit in one transaction and Wellness Plan on another transaction expected to show Total prospect=1 not 2 in DC Conversion Report.
        //TJC-1754 Hotfix
        $visitDate = new DateTime($bean->date_entered);
        $visitDate->setTimezone(new DateTimeZone($userTZ));
        $today = $visitDate->format('Y-m-d');
        //$visitnextDay = date('Y-m-d', strtotime($visitDate. ' + 1 days'));
        // $today = date("Y-m-d");
        $dbInstance =  DBManagerFactory::getInstance('reports');
        $SugarQuery = new SugarQuery($dbInstance);
        $SugarQuery->select(array('tj_purchases.id'));
        $SugarQuery->from(BeanFactory::newBean('TJ_Purchases'),array('team_security' => false));
        $SugarQuery->distinct(true);
        $SugarQuery->joinTable('contacts_tj_purchases_1_c', array('alias' => 'pp'))->on()->equalsField('pp.contacts_tj_purchases_1tj_purchases_idb','tj_purchases.id');
        $SugarQuery->joinTable('tj_clinics_tj_purchases_1_c', array('alias' => 'cp'))->on()->equalsField('cp.tj_clinics_tj_purchases_1tj_purchases_idb','tj_purchases.id');
        $SugarQuery->joinTable('users_tj_purchases_2_c', array('joinType' => 'LEFT', 'alias' => 'up'))->on()->equalsField('up.users_tj_purchases_2tj_purchases_idb', 'tj_purchases.id');
        $SugarQuery->joinTable('tj_purchases_tj_visits_1_c', array('joinType' => 'LEFT', 'alias' => 'pv'))->on()->equalsField('pv.tj_purchases_tj_visits_1tj_purchases_ida', 'tj_purchases.id');

        $SugarQuery->where()
        ->equals('pp.contacts_tj_purchases_1contacts_ida', $bean->contacts_tj_visits_1contacts_ida)
        ->equals('cp.tj_clinics_tj_purchases_1tj_clinics_ida', $bean->tj_clinics_tj_visits_1tj_clinics_ida)
        // TJC-1771
        //->equals('status', 'Active')
        //->lte('saledate_date', $today)
        //->equals('saledate_date', $today)
        //->dateBetween('statuschangedate',array($today,$visitnextDay))
        //->dateRange('date_entered', $today)
        ->isNull('up.users_tj_purchases_2users_ida');
        //->notequals('pv.tj_purchases_tj_visits_1tj_visits_idb', $bean->id);
        //->notNull('pv.tj_purchases_tj_visits_1tj_visits_idb', $bean->id);
        $SugarQuery->whereRaw("date(CONVERT_TZ(saledate, 'UTC','$userTZ')) = '$today'");

        $SugarQuery->orderBy('tj_purchases.id');

        // $preparedStmt = $SugarQuery->compile();
        // $sql = $preparedStmt->getSQL();
        // $parameters = $preparedStmt->getParameters();

        $resultPurchases = $SugarQuery->execute();             

        if (count($resultPurchases)) {

            foreach ($resultPurchases as $purchaseArray) {

                $purchaseBean = BeanFactory::retrieveBean("TJ_Purchases", $purchaseArray['id'], array('disable_row_level_security' => true));

                $purchaseBean->load_relationship('users_tj_purchases_2');
                $purchaseBean->users_tj_purchases_2->add($current_user->id);                               
                //break;     
            }
        }
                        
        // if (count($relatedPurchases)) {
        //     $timedate = new TimeDate();
        //     $visitDateEntered = date_create($bean->date_entered);
        //     $visitDate = $timedate->asDbDate($visitDateEntered);
        //     foreach ($relatedPurchases as $purchaseBean) {
        //         $visitDateEntered = date_create($purchaseBean->date_entered);
        //         $purchaseDate = $timedate->asDbDate($visitDateEntered);
        //         if ($visitDate == $purchaseDate) {
        //             $purchaseBean->load_relationship('users_tj_purchases_2');
        //             $purchaseBean->users_tj_purchases_2->add($current_user->id);
        //             break;
        //         }
        //     }
        // }        
    }

    public function completeOpenWCMTask($patientID){
        $dbInstance =  DBManagerFactory::getInstance('reports');
        $SugarQuery = new SugarQuery($dbInstance);
        $SugarQuery->from(BeanFactory::newBean('Tasks'), array('team_security' => false));
        $SugarQuery->where()->in('task_type_c', array('WC Member Education','30 day no visit'));
        $SugarQuery->where()->equals('parent_type', "Contacts")
                            ->notIn('status',array('Dropped','Complete'))
                            ->equals('parent_id', $patientID);
        $SugarQuery->select(array('id'));
        $result = $SugarQuery->execute();

        foreach ($result as $task) {
            if(!empty($task['id'])){
		        $beanTask = BeanFactory::retrieveBean('Tasks', $task['id'], array('disable_row_level_security' => false));
                if (!empty($beanTask->id)) {
                    $beanTask->status = "Complete";
                    $beanTask->save();
                }
            }
        }
    }
}
