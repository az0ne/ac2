<?php
/**
 * @package Analytical Center 2
 * @see for EN http://hack4sec.pro/wiki/index.php/Analytical_Center_2_en
 * @see for RU http://hack4sec.pro/wiki/index.php/Analytical_Center_2
 * @license MIT
 * @copyright (c) Anton Kuzmin <http://anton-kuzmin.ru> (ru) <http://anton-kuzmin.pro> (en)
 * @author Anton Kuzmin
 */
class Files extends Common {
    protected $_name = 'files';
    protected $_rowClass = 'File';

    public function getListPaginator($projectId, $type, $parent, $objectId, $search, $page) {
        if (!$type) {
            $select = $this->_getListAll($projectId);
        } elseif ($type and !$parent and !$objectId) {
            $select = $this->_getListAllByType($projectId, $type);
        } elseif ($type and $parent and !$objectId) {
            $select = $this->_getListByTypeAndParent($projectId, $type, $parent);
        } else {
            $select = $this->_getListByObjectId($projectId, $type, $objectId);
        }
        if (strlen($search)) {
            $select->where("f.name LIKE ? OR f.comment LIKE ?", "%$search%", "%$search%");
        }
        $select->order("name ASC");

        $paginator = Zend_Paginator::factory(
            $select
        )->setItemCountPerPage(Zend_Registry::get('config')->pagination->files)
            ->setCurrentPageNumber($page);

        Zend_Paginator::setDefaultScrollingStyle('Sliding');
        $view = Zend_View_Helper_PaginationControl::setDefaultViewPartial(
            'paginator.phtml'
        );
        $paginator->setView($view);
        return $paginator;
    }

    private function _getListByObjectId($projectId, $type, $objectId) {
        return $this->_getListAllByType($projectId, $type)->where('object_id = ?', $objectId);
    }

    private function _getListByTypeAndParent($projectId, $type, $parent) {
        $select = $this->_getListAllByType($projectId, $type);
        switch ($type) {
            case 'web-app':
                $select->where("d.id = ?", $parent);
                break;
            case 'server-software':
                $select->where("s.id = ?", $parent);
                break;
            case 'domain':
                $select->where("s.id = ?", $parent);
                break;
            default:
                throw new Exception("Unknown list type '{$type}'");
        }
        return $select;
    }

    private function _getListAllByType($projectId, $type) {
        switch ($type) {
            case 'web-app':
                $select = $this->getAdapter()->select()
                    ->from(['f' => 'files'], ['*'])
                    ->join(['w' => 'web_apps'], 'f.object_id = w.id', [])
                    ->join(['d' => 'domains'], 'w.domain_id = d.id', [])
                    ->join(['s' => 'servers'], "d.server_id = s.id AND s.project_id = $projectId", [])
                    ->where("f.type = 'web-app'");
                break;
            case 'server':
                $select = $selectServers = $this->getAdapter()->select()
                    ->from(['f' => 'files'], ['*'])
                    ->join(['s' => 'servers'], "s.project_id = $projectId AND s.id = f.object_id", [])
                    ->where("f.type = 'server'");
                break;
            case 'server-software':
                $select = $this->getAdapter()->select()
                    ->from(['f' => 'files'], ['*'])
                    ->join(['s' => 'servers'], "s.project_id = $projectId", [])
                    ->join(['ss' => 'servers_software'], 'ss.server_id = s.id AND ss.id = f.object_id', [])
                    ->where("f.type = 'server-software'");
                break;
            case 'domain':
                $select = $this->getAdapter()->select()
                    ->from(['f' => 'tasks'], ['*'])
                    ->join(['s' => 'servers'], "s.project_id = $projectId", [])
                    ->join(['d' => 'domains'], 'd.server_id = s.id AND d.id = f.object_id', [])
                    ->where("f.type = 'domain'");
                break;
            case 'project':
                $select = $this->getAdapter()->select()
                    ->from(['f' => 'files'], ['*'])
                    ->where("f.type = 'project'")->where("f.object_id = $projectId");
                break;
            default:
                throw new Exception("Unknown list type '{$type}'");
        }
        return $select;
    }

    private function _getListAll($projectId) {
        return $this->getAdapter()->select()->union(
            [
                $this->_getListAllByType($projectId, 'web-app'),
                $this->_getListAllByType($projectId, 'server-software'),
                $this->_getListAllByType($projectId, 'server'),
                $this->_getListAllByType($projectId, 'project'),
            ]
        );
    }

    public function add($data) {
        $config = Zend_Registry::get('config');

        $data['hash'] = md5(time() . rand(1000000, 9000000));
        rename($config->paths->storage . "/" . $data['name'], $config->paths->storage . "/" . $data['hash']);

        $task = $this->createRow($data);
        $task->updated = $task->when_add = time();
        $task->save();
    }

    public function edit($data) {
        $file = $this->get($data['id']);
        $file->updated = time();
        $file->comment = $data['comment'];
        $file->save();
    }

    public function getObjectsPairsList($type, $parentId) {
        switch ($type) {
            case 'server-software':
                $list = (new Servers_Software())->getPairsList($parentId, "name");
                break;
            case 'web-app':
                $list = (new WebApps())->getPairsList($parentId, "name");
                break;
            case 'domain':
                $list = (new Domains())->getPairsList($parentId, "name");
                break;
            case 'server':
                $list = (new Servers())->getPairsList($parentId, "name");
                break;
        }
        return $list;
    }

    public function getParentsPairsList($projectId, $type) {
        switch ($type) {
            case 'server-software':
                $list = (new Servers())->getPairsList($projectId, "name");
                break;
            case 'web-app':
                $list = (new Domains())->getPairsListByProjectId($projectId, "name");
                break;
            case 'domain':
                $list = (new Servers())->getPairsList($projectId, "name");
                break;
        }
        return $list;
    }

    public function getCountByProjectId($id) {
        $projects = $this->getAdapter()->fetchOne(
            "SELECT COUNT(f.id) FROM files f WHERE f.type='project' AND f.object_id = $id"
        );
        $domains = $this->getAdapter()->fetchOne(
            "SELECT COUNT(f.id) FROM files f
			 JOIN domains d ON f.object_id = d.id AND f.type = 'domain'
             JOIN servers s ON d.server_id = s.id
             JOIN projects p ON s.project_id = p.id
             WHERE p.id = $id"
        );
        $server = $this->getAdapter()->fetchOne(
            "SELECT COUNT(f.id) FROM files f
             JOIN servers s ON f.object_id = s.id AND f.type = 'server'
             JOIN projects p ON s.project_id = p.id
             WHERE p.id = $id"
        );
        $webApps = $this->getAdapter()->fetchOne(
            "SELECT COUNT(f.id) FROM files f
			 JOIN web_apps a ON f.object_id = a.id AND f.type='web-app'
             JOIN domains d ON a.domain_id = d.id
             JOIN servers s ON d.server_id = s.id
             JOIN projects p ON s.project_id = p.id
             WHERE p.id = $id"
        );
        $spo = $this->getAdapter()->fetchOne(
            "SELECT COUNT(f.id) FROM files f
			 JOIN servers_software ss ON f.object_id = ss.id AND f.type = 'server-software'
             JOIN servers s ON ss.server_id = s.id
             JOIN projects p ON s.project_id = p.id
             WHERE p.id = $id"
        );
        return $webApps + $spo + $server + $domains + $projects;
    }

    public function getCountByTypeAndId($type, $id) {
        return $this->getAdapter()->fetchOne(
            "SELECT COUNT(id) FROM {$this->_name} WHERE type='$type' AND object_id = $id"
        );
    }
} 