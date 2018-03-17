<?php
/**
 * RGPD compliance plugin for contact form and newsletter subscription
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.acymailing
 * @copyright   Copyright (C) 2005-2018  Better Web - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */
 
// No direct access
defined('_JEXEC') or die('Restricted access');

 use \Joomla\CMS\Date\Date;
 
// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';

/**
 * RGPD compliance plugin for contact form and newsletter subscription
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.gdpr
 * @since       3.8
 */

class PlgFabrik_FormGdpr extends PlgFabrik_Form
{
	protected $html = null;

	/**
	 * Set up the html to be injected into the bottom of the form
	 *
	 * @return  void
	 */

	public function getBottomContent()
	{
		$params    = $this->getParams();
		$formModel = $this->getModel();
		$form 	   = $formModel->getForm();

		if ($params->get('gdpr_contact', true) || $params->get('gdpr_acymailing', true))
		{
			$this->html = '';
			$this->html .= $form->errMsg;
			
			if($params->get('gdpr_fieldset'))
			{
				$this->html .= '<fieldset class="' . $params->get('gdpr_fieldset_class') . '">'
							  .'<legend class="' . $params->get('gdpr_legend_class') . '">' . $params->get('gdpr_legend') . '</legend>';
			}
			
			if ($params->get('gdpr_contact', true))
			{
				$this->html .= '<div class="contact_consent">'
							.'<input id="fabrik_contact_consent" type="checkbox" name="fabrik_contact_consent" value="1" style="margin-right: 10px;">'
							.'<label for="fabrik_contact_consent">' . FText::_($params->get('gdpr_consent_text')) . '</label>'
							.'</div>';
			}
			
			if($params->get('gdpr_acymailing', true))
			{
				if($this->checkAcymailing())
				{
					$this->html .= '<div class="acymailing_consent">'
								.'<input id="fabrik_acymailing_signup" type="checkbox" name="fabrik_acymailing_signup" value="1" style="margin-right: 10px;">'
								.'<label for="fabrik_acymailing_signup">' . FText::_($params->get('acymailing_signuplabel')) . '</label>'
								.'</div>';
				}
				else
				{
					$this->html = '';
				}
			}
		   
			if($params->get('gdpr_fieldset'))
			{
				$this->html .= '</fieldset>';
			}
		}
		else
		{
			$this->html = '';
		}
	}

	/**
	 * Inject custom html into the bottom of the form
	 *
	 * @param   int  $c  Plugin counter
	 *
	 * @return  string  html
	 */

	public function getBottomContent_result($c)
	{
		return $this->html;
	}

	/**
	 * Run right before the form processing
	 * keeps the data to be processed or sent if consent is not given
	 *
	 * @return	bool
	 */
	
	public function onBeforeProcess()
	{
		$filter  = JFilterInput::getInstance();
		$post    = $filter->clean($_POST, 'array');
		$contact = array_key_exists('fabrik_contact_consent', $post);
		$formModel = $this->getModel();
		
		$this->data = $formModel->formData;
		
		if(!$contact)
		{
			$form = $formModel->getForm();
	
			$formModel->errors['consent_required'] = array(FText::_('PLG_FORM_GDPR_PLEASE_CONFIRM_CONSENT'));
			$form->error = '';
			$form->errMsg = '<div class="fabrikMainError alert alert-error">
							<button class="close" data-dismiss="alert">×</button>' .
							FText::_('PLG_FORM_GDPR_PLEASE_CONFIRM_CONSENT') . '</div>';;
			
			return false;
		}
	 }
	
	/**
	 * Run right at the end of the form processing
	 * form needs to be set to record in database for this to hook to be called
	 *
	 * @return	bool
	 */

	public function onAfterProcess()
	{
		$params    = $this->getParams(); 
		$formModel = $this->getModel();
		$data 	   = $this->getProcessData();
		$filter    = JFilterInput::getInstance();
		$post      = $filter->clean($_POST, 'array');
		$contact   = array_key_exists('fabrik_contact_consent', $post);
		$acy	   = array_key_exists('fabrik_acymailing_signup', $post);		
		
		// Record consent
		// If consent is missing for contact and newsletter, do nothing
		if ($formModel->isNewRecord() && !$contact && !$acy)
		{
			return;
		}
		
		// When editing a record, don't process consent again
		if($formModel->isNewRecord())
		{
			$now 	   = new JDate('now');
			$reference = $data['listid'] . '.' . $data['formid'] . '.' . $data['rowid'];
			$listId	   = $data['listid'];
			
			if($contact)
			{
				$contactId 	    = $data['rowid'];
				$contactMessage = $params->get('gdpr_consent_text');
			}
			
			if($acy)
			{
				if($this->checkAcymailing())
				{
					$myUser = new stdClass();
			
					$emailField	= $params->get('acymailing_email');			    
					if(!$emailField)
					{
						throw new RuntimeException(FText::_('PLG_FORM_GDPR_NO_EMAIL_ERROR_MSG'));
						return false;
					}
					else
					{
						$emailKey 	   = $formModel->getElement($emailField, true)->getFullName();
						$myUser->email = $formModel->formDataWithTableName[$emailKey];
					}
					
					$nameField = $params->get('acymailing_name');
					if($nameField)
					{
						$nameKey 	  = $formModel->getElement($nameField, true)->getFullName();
						$myUser->name = $formModel->formDataWithTableName[$nameKey];
					}
					else
					{
						$myUser->name ='';
					}
					
					$subscribe = explode(',', $params->get('acymailing_listid'));
					
					$acymailingUserId  = $this->acymailingSubscribe($myUser, $subscribe, $formModel);
					$acymailingListIds = $params->get('acymailing_listid');
					$acymailingMessage = $params->get('acymailing_signuplabel');				
				}
			}
		
		$db    	 = JFactory::getDBO();
		$query 	 = $db->getQuery( true );
		$columns = array('id', 'date_time', 'reference', 'list_id', 'contact_id', 'acymailing_user_id', 'acymailing_list_ids', 'contact_message', 'acymailing_message', 'ip');
		$values  = array('NULL', $db->quote($now->format('Y-m-d H:i:s')), $db->quote($reference), $listId, $db->quote($contactId), $db->quote($acymailingUserId), $db->quote($acymailingListIds), $db->quote($contactMessage), $db->quote($acymailingMessage), $db->quote($_SERVER['REMOTE_ADDR']));
		$query->insert($db->quoteName('#__fabrik_gdpr'))
			  ->columns($db->quoteName($columns))
			  ->values(implode(',', $values));
		$db->setQuery($query);
		$db->execute();
		}
	}
	
	/**
	 * Check whether Acymailing component is installed
	 * returns false and error message if not installed
	 *
	 * @return	bool
	 */
	
	protected function checkAcymailing()
	{
		if(!include_once(rtrim(JPATH_ADMINISTRATOR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.'com_acymailing'.DIRECTORY_SEPARATOR.'helpers'.DIRECTORY_SEPARATOR.'helper.php'))
		{
			throw new RuntimeException(FText::_('PLG_FORM_GDPR_ACYMAILING_ERROR_MSG'));
			return false;
		}
		
		return true;
	}
	
	/**
	 * Add user as an Acymailing subscriber
	 *
	 * @return	bool
	 */
	
	protected function acymailingSubscribe($user, $lists, $formModel)
	{
		if(!include_once(rtrim(JPATH_ADMINISTRATOR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.'com_acymailing'.DIRECTORY_SEPARATOR.'helpers'.DIRECTORY_SEPARATOR.'helper.php'))
		{
			throw new RuntimeException(FText::_('PLG_FORM_GDPR_ACYMAILING_ERROR_MSG'));
			return false;
		}
		
		$user->confirmed = 0;
 
		$subscriberClass = acymailing_get('class.subscriber');
 
		$subid = $subscriberClass->save($user);
 
		$newSubscription = array();
		if(!empty($lists))
		{
			foreach($lists as $listId)
			{
				$newList = array();
				
				if ($formModel->isNewRecord())
				{
					$newList['status'] 		  = 1;
					$newSubscription[$listId] = $newList;
				}
			}
		}
		
		if(empty($newSubscription)) return $subid;
		
		if(empty($subid)) return false;
		
		$subscriberClass->saveSubscription($subid,$newSubscription);
		
		return $subid;
	}
}