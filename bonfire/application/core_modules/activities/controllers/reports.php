<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/*
	Copyright (c) 2011 Lonnie Ezell

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.
*/

/*
	Class: Activities Reports Context

	Allows the administrator to view the activity logs.
*/
class Reports extends Admin_Controller {

	//--------------------------------------------------------------------

	public function __construct()
	{
		parent::__construct();

		$this->auth->restrict('Site.Reports.View');
		$this->auth->restrict('Bonfire.Activities.Manage');

		$this->lang->load('activities');
		$this->lang->load('datatable');
		$this->load->model('activities/Activity_model', 'activity_model');

		Template::set('toolbar_title', lang('activity_title'));

		Assets::add_js($this->load->view('reports/activities_js', null, true), 'inline');
		Assets::add_js( array ( Template::theme_url('js/jquery.dataTables.min.js' )) );
		Assets::add_css( array ( Template::theme_url('css/datatable.css') ) ) ;
		Assets::add_module_css ('activities', 'datatables.css');


		Template::set_block('sub_nav', 'reports/_sub_nav');
	}

	//--------------------------------------------------------------------

	/*
		Method: index()

		Lists all log files and allows you to change the log_threshold.
	*/
	public function index()
	{
		// get top 5 modules
		$this->db->group_by('module');
		Template::set('top_modules', $this->activity_model->select('module, COUNT(module) AS activity_count')->limit(5)->order_by('activity_count', 'DESC')->find_all() );

		// get top 5 users and usernames
		$this->db->join('users', 'activities.user_id = users.id', 'left');
		$query = $this->db->select('username, user_id, COUNT(user_id) AS activity_count')->group_by('user_id')->order_by('activity_count','DESC')->limit(5)->get($this->activity_model->get_table());
		Template::set('top_users', $query->result());

		Template::set('users', $this->user_model->find_all());
		Template::set('modules', module_list());
		Template::set('activities', $this->activity_model->find_all());
		Template::render();
	}

	//--------------------------------------------------------------------

	/*
		Method: activity_user()

		Shows the activites for the specified user.

		Parameters:
			none
	*/
	public function activity_user()
	{

		if (!has_permission('Activities.Own.View') || !has_permission('Activities.User.View')) {
			Template::set_message(lang('activity_restricted'), 'error');
			Template::redirect(SITE_AREA .'/reports/activities');
		}

		return $this->_get_activity();
	}

	//--------------------------------------------------------------------

	/*
		Method: activity_module()

		Shows the activites for the specified module.

		Parameter:
			none
	*/
	public function activity_module()
	{
		if (has_permission('Activities.Module.View')) {
			return $this->_get_activity('activity_module');
		}

		Template::set_message(lang('activity_restricted'), 'error');
		Template::redirect(SITE_AREA .'/reports/activities');
	}

	//--------------------------------------------------------------------

	/*
		Method: activity_date()

		Shows the activites before the specified date.

		Parameter:
			none
	*/
	public function activity_date()
	{
		if (has_permission('Activities.Date.View')) {
			return $this->_get_activity('activity_date');
		}

		Template::set_message(lang('activity_restricted'), 'error');
		Template::redirect(SITE_AREA .'/reports/activities');
	}


	//--------------------------------------------------------------------

	/*
		Method: delete()

		Deletes the entries in the activity log for the specified area.

		Parameter:
			none
	*/
	public function delete()
	{
		$action = $this->uri->segment(5);
		$which  = $this->uri->segment(6);

		// check for permission to delete this
		$permission = str_replace('activity_', '',$action);
		if (!has_permission('Activities.'.ucfirst($permission).'.Delete')) {
			Template::set_message(lang('activity_restricted'), 'error');
			Template::redirect(SITE_AREA .'/reports/activities');
		}

		if (empty($action))
		{
			Template::set_message('Delete section not specified', 'error');
			Template::redirect(SITE_AREA .'/reports/activities');
		}

		if (empty($which))
		{
			Template::set_message('Delete value not specified', 'error');
			Template::redirect(SITE_AREA .'/reports/activities');
		}

		// different delete where statement switch
		switch ($action)
		{
			case 'activity_date':
				$value = 'activity_id';
			break;

			case 'activity_module':
				$value = 'module';
			break;

			default:
				$value = 'user_id';
			break;
		}

		// set a default delete then check if delete "all" was chosen
		$delete = ($value == 'activity_id') ? $value ." < '".$which."'" : $value ." = '".$which."'";
		if ($which == 'all')
		{
			$delete = $value ." != 'tsTyImbdOBOgwIqtb94N4Gr6ctatWVDnmYC3NfIfczzxPs0xZLNBnQs38dzBYn8'";
		}

		// check if they can delete their own stuff
		$delete .= (has_permission('Activities.Own.Delete')) ? '' : " AND user_id != '" . $this->auth->user_id()."'";

		$affected = $this->activity_model->delete_where($delete);
		if (is_numeric($affected))
		{
			Template::set_message(sprintf(lang('activity_deleted'),$affected),'success');
			$this->activity_model->log_activity($this->auth->user_id(), 'deleted '.$affected.' activities', 'activities');
		}
		else if (isset($affected))
		{
			Template::set_message(lang('activity_nothing'),'attention');
		}
		else
		{
			Template::set_message('Error : '.$this->activity_model->error, 'error');
		}

		// Redirecting
		Template::redirect(SITE_AREA .'/reports/activities');
	}


	//--------------------------------------------------------------------

	//--------------------------------------------------------------------
	// !PRIVATE METHODS
	//--------------------------------------------------------------------

	/*
		Method: _get_activity()

		Gets all the activity based on parameters passed

		Parameter:
			$which		- which filter to use
			$find_value	- the value to filter by
	*/
	public function _get_activity($which='activity_user',$find_value=FALSE)
	{
		Template::set('filter', $this->input->post('activity_select'));

		// set a couple default variables
		$options = array(0 => 'All');
		$name = 'All';

		// check if $find_value has anything in it
		if ($find_value === FALSE)
		{
			$find_value = ($this->input->post('activity_select') == '') ? $this->uri->segment(5) : $this->input->post('activity_select');
		}

		switch ($which)
		{
			case 'activity_module':
				$modules = module_list();
				foreach ($modules as $mod)
				{
					$options[$mod] = $mod;

					if ($find_value == $mod)
					{
						$name = ucwords($mod);
					}
				}
				$where = 'module';
			break;

			case 'activity_date':
				foreach($this->activity_model->find_all() as $e)
				{
					$options[$e->activity_id] = $e->created_on;

					if ($find_value == $e->activity_id)
					{
						$name = $e->created_on;
					}
				}
				$where = 'activity_id';
			break;

			default:
				foreach($this->user_model->find_all() as $e)
				{
					$options[$e->id] = $e->username;

					if ($find_value == $e->id)
					{
						$name = $e->username;
					}
				}
				$where = 'user_id';
			break;
		}

		// set some vars for the view
		$vars = array(
			'which'			=> $which,
			'view_which'	=> ucwords(lang($which)),
			'name'			=> $name,
			'delete_action'	=> $where,
			'delete_id'		=> $find_value
		);
		Template::set('vars', $vars);

		// if we have a filter, apply it here
		$this->db->order_by($where,'asc');
		if ($find_value)
		{
			$where = ($where == 'activity_id') ? 'activity_id <' : $where;
			$this->db->where($where,$find_value);

			$this->db->where('activities.deleted', 0);
			$total = $this->activity_model->count_by($where, $find_value);

			// set this again for use in the main query
			$this->db->where($where,$find_value);
		}
		else
		{
			$total = $this->activity_model->count_by('activities.deleted', 0);
		}

		// does user have permission to see own records?
		if (!has_permission('Activities.Own.View'))
		{
			$this->db->where('activities.user_id != ', $this->auth->user_id());
		}

		// don't show the deleted records
		$this->db->where('activities.deleted', 0);

		// Pagination
		$this->load->library('pagination');

		$offset = $this->input->get('per_page');

		$limit = $this->settings_lib->item('site.list_limit');

		$this->pager['base_url'] 			= current_url() .'?';
		$this->pager['total_rows'] 			= $total;
		$this->pager['per_page'] 			= $limit;
		$this->pager['page_query_string']	= true;

		$this->pagination->initialize($this->pager);

		// get the activities
		$this->db->join('users', 'activities.user_id = users.id', 'left');
		$this->db->order_by('activity_id','desc'); // most recent stuff on top
		$this->db->select('activity, module, activities.created_on AS created, username');
		Template::set('activity_content', $this->activity_model->limit($limit, $offset)->find_all());

		Template::set('select_options', $options);

		Template::set_view('reports/view');
		Template::render();
	}


	//--------------------------------------------------------------------

}
