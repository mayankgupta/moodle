<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once($CFG->dirroot.'/mod/scorm/datamodels/scormlib.php');

function scorm_seq_flow_tree_traversal ($activity, $direction, $childrenflag, $prevdirection, $seq, $userid){
    $revdirection = false;
    $parent = scorm_get_parent ($activity);
    $children = scorm_get_available_children ($parent);
    $childrensize = sizeof ($children);

    if (($prevdirection != null && $prevdirection == 'backward') && ($children[$childrensize-1]->id == $activity->id)){
        $direction = 'backward';
        $children[0] = $activity;
        $revdirection = true;
    }

    if($direction == 'forward'){
        $ancestors = scorm_get_ancestors($activity);
        $ancestorsroot = array_reverse($ancestors);
        $preorder = scorm_get_preorder($ancestorsroot);
        $preordersize = sizeof($preorder);
        
        if (($activity->id == $preorder[$preordersize-1]->id) || (($activity->parent == '/') && !($childrenflag))){
            //scorm_seq_terminate_descent_attempts($ancestorsroot, $userid); TODO: undefined function
            $seq->endsession = true;
            $seq->nextactivity = null;
            return $seq;
        }
        if (scorm_is_leaf ($activity) || !$childrenflag){
            if ($children[$childrensize-1]->id == $activity->id){

                $seq = scorm_seq_flow_tree_traversal ($parent, $direction, false, null, $seq,$userid);
                // I think it's not necessary to do a return in here
            }
            else {
                $parent = scorm_get_parent($activity);
                $children = scorm_get_available_children($parent);
                $seq->traversaldir = $direction;
                $siblings = scorm_get_siblings($activity);
                if (isset($siblings[$activity->id + 1])) {
                    $seq->nextactivity = $siblings[$activity->id + 1];
                    return $seq;
                } else {
                    $ch = scorm_get_children($siblings[0]);
                    $seq->nextactivity = $ch[0];
                    return $seq;
                }
            }
        } else {
            if ($children = scorm_get_available_children($activity)){
                $seq->traversaldir = $direction;
                $seq->nextactivity = $children[0];
                return $seq;
            }
            else{
                $seq->traversaldir = null;
                $seq->nextactivity = $children[0];
                $seq->exception = 'SB.2.1-2';
                return $seq;
            }
        }

    } else if ($direction == 'backward') {

        if ($activity->parent == '/'){
            $seq->traversaldir = null;
            $seq->nextactivity = null;
            $seq->exception = 'SB.2.1-3';
            return $seq;
         }
         if (scorm_is_leaf ($activity) || !$childrenflag){
             if (!$revdirection) {
                 if (isset($parent->forwardonly) && ($parent->forwardonly == true)) {
                     $seq->traversaldir = null;
                     $seq->nextactivity = null;
                     $seq->exception = 'SB.2.1-4';
                     return $seq;
                 }
             }
             if ($children[0]->id == $activity->id) {
                $seq = scorm_seq_flow_tree_traversal ($parent, 'backward', false, null, $seq, $userid);
                return $seq;
             } else {
                $siblings = scorm_get_siblings($activity);
                if (isset($siblings[$activity->id - 1])) {
                    $seq->nextactivity = $siblings[$activity->id - 1];
                    $seq->traversaldir = $direction;
                    return $seq;
                } 
             }
         } else {
             if (!empty($children)){
                 $activity = scorm_get_sco($activity->id);
                 if (isset($parent->flow) && ($parent->flow == true)) {
                     $children = scorm_get_children($activity);
                     $seq->traversaldir = 'forward';
                     $seq->nextactivity = $children[0];
                     return $seq;

                 } else {
                     $children = scorm_get_children($activity);
                     $seq->traversaldir = 'backward';
                     $seq->nextactivity = $children[sizeof($children)-1];
                     return $seq;
                 }

             } else {
                     $seq->traversaldir = null;
                     $seq->nextactivity = null;
                     $seq->exception = 'SB.2.1-2';
                     return $seq;
             }
         }
    }
}

function scorm_seq_flow_activity_traversal ($activity, $userid, $direction, $childrenflag, $prevdirection, $seq, $userid){//returns the next activity on the tree, traversal direction, control returned to the LTS, (may) exception
    $activity = scorm_get_sco($activity->id);
    $parent = scorm_get_parent($activity);
    if (!isset($parent->flow) || ($parent->flow == false)) {
        $seq->deliverable = false;
        $seq->exception = 'SB.2.2-1';
        $seq->nextactivity = $activity;
        return $seq;
    }
    $rulecheck = scorm_seq_rules_check($activity, 'skip');
    if ($rulecheck != null) {
        $seq = scorm_seq_flow_tree_traversal ($activity, $direction, false, $prevdirection, $seq, $userid);//endsession and exception
        $skip = scorm_evaluate_condition ($rulecheck, $activity, $userid);
        if ($skip) {
            $seq = scorm_seq_flow_tree_traversal ($activity, $direction, false, $prevdirection, $seq, $userid);
            $seq = scorm_seq_flow_activity_traversal($seq->nextactivity, $userid, $direction, $childrenflag, $prevdirection, $seq, $userid);
        } else if (!empty($seq->identifiedactivity)) {
            $seq->nextactivity = $activity;
        }
        return $seq;
    }

    $ch = scorm_check_activity ($activity, $userid);

    if ($ch) {
        $seq->deliverable = false;
        $seq->exception = 'SB.2.2-2';
        $seq->nextactivity = $activity;
        return $seq;

    }

    if (!scorm_is_leaf($activity)){
        $seq = scorm_seq_flow_tree_traversal ($activity, $direction, true, null, $seq, $userid);

        if ($seq->identifiedactivity == null){
            $seq->deliverable = false;
            $seq->nextactivity = $activity;
            return $seq;
        } else {
            if($direction == 'backward' && $seq->traversaldir == 'forward'){
                $seq = scorm_seq_flow_activity_traversal($seq->identifiedactivity, $userid, 'forward', $childrenflag, 'backward', $seq, $userid);
            }
            else {
                scorm_seq_flow_activity_traversal($seq->identifiedactivity, $userid, $direction, $childrenflag, null, $seq, $userid);
            }
            return $seq;
        }

    }
    
    $seq->deliverable = true;
    $seq->nextactivity = $activity;
    return $seq;
}

function scorm_seq_flow ($activity, $direction, $seq, $childrenflag, $userid){
    //TODO: $PREVDIRECTION NOT DEFINED YET
    $prevdirection = null;
    $seq = scorm_seq_flow_tree_traversal ($activity, $direction, $childrenflag, $prevdirection, $seq, $userid);
    if (empty($seq->identifiedactivity)) {//if identifies
        $seq->identifiedactivity = $activity;
        $seq->deliverable = false;
    } 
    if (!empty($seq->nextactivity)) {
        $seq = scorm_seq_flow_activity_traversal($seq->nextactivity, $userid, $direction, $childrenflag, $prevdirection, $seq, $userid);
    }
    return $seq;
}
