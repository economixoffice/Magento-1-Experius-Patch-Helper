<?php
$baseDir = '/'.trim(realpath(dirname(__FILE__) . '/../../..'), '/'); 
if (file_exists('abstract.php')) {
    require_once 'abstract.php';
} elseif (file_exists('shell/abstract.php')) {
	require_once 'shell/abstract.php';
} elseif (file_exists($baseDir . '/shell/abstract.php')){
    require_once $baseDir . '/shell/abstract.php';
} else {
	exit("Abstract file not found.");
}
class Mage_Shell_PatchHelper extends Mage_Shell_Abstract{
    
	private $rewrites = array();
    
    private $rewritesFlat = array();
    
    public function run(){
        
        if($this->getArg('patch')){
            $patchFilePath = Mage::getBaseDir() . DS . $this->getArg('patch');
        
            if(file_exists($patchFilePath)){
                $fp = @fopen($patchFilePath, 'r'); 
                if ($fp) {
                   $lines = explode("\n", fread($fp, filesize($patchFilePath)));
                   
                   foreach($lines as $line){
                        if(preg_match("/\+{3} (.*)/",$line, $matches)){
                            $patchedFiles[$matches[1]] = $matches[1];
                        }
                   }
                }
            } else {
                echo "Patch file not found \n";
                return;
            }
            
            echo "\n\n";
            echo "\e[41m Check Local Overwrites \e[0m\n";
            foreach($patchedFiles as $patchedFile){
                if(preg_match('/app\/code\/core\/Mage/',$patchedFile)){
                    $this->checkLocalOverwrite($patchedFile);
                }
            }
    
            echo "\n\n";    
            echo "\e[41m Check Rewrites \033[0m\n";
            foreach($patchedFiles as $patchedFile){
                if(preg_match('/.php/',$patchedFile) && preg_match('/app\/code\/core\/Mage/',$patchedFile)){
                    $this->checkRewrites($patchedFile);
                }
            }
            echo "\n\n";
                
            echo "\e[41m Check Frontend Template Files \e[0m\n";
            $this->checkTemplateFiles($patchedFiles);
            echo "\n\n";

            echo "\e[41m Check Skin Files \e[0m\n";
            $this->checkSkinFiles($patchedFiles);
            echo "\n\n";

            echo "\e[43m Check similar name phtml files in other folders \e[0m\n";
            foreach($patchedFiles as $patchedFile){
                if(preg_match('/.phtml/',$patchedFile) && preg_match('/app\/design\/frontend\/base\/default/',$patchedFile)){
                    $this->searchTemplateNames($patchedFile);
                }
            }

            echo "\n\n";
            echo "\e[43m Check similar name skin js files in other folders \e[0m\n";
            foreach($patchedFiles as $patchedFile){
                if(preg_match('/.js/',$patchedFile) && preg_match('/skin\/frontend\/base\/default/',$patchedFile)){
                    $this->searchSkinNames($patchedFile);
                }
            }

		} else {
            echo "Add Patch Filename. php patchHelper.php --patch PATCH_SUPEE-8788_CE_1.9.0.1_v1-2016-10-11-06-57-03.sh \n";
        }
        
    }
    
    protected function checkLocalOverwrite($filename){
            
        $localOverwriteFilename = Mage::getBaseDir('app') . str_replace('app/code/core/Mage','/code/local/Mage',$filename);
        if(file_exists($localOverwriteFilename)){
            echo $localOverwriteFilename . "\n";
        }
    }
    
    protected function getClassNameFromFile($filename){
        $className  = str_replace('/','_',str_replace(array('app/code/core/','.php'),'',$filename));
        
        if(preg_match('/_controllers_/',$className)){
            $className = str_replace('_controllers','',$className);
        }
        
        return $className;
    }
	
	protected function getFileNameFromClass($className){
        $fileName  = str_replace('_','/',$className) . '.php';
		if(file_exists(Mage::getBaseDir('app') . '/code/local/' . $fileName)){
			return Mage::getBaseDir('app') . '/code/local/' . $fileName;
		} elseif(file_exists(Mage::getBaseDir('app') . '/code/community/' . $fileName)){
			return Mage::getBaseDir('app') . '/code/community/' . $fileName;
		}
		
        return $fileName;
    }
    
    protected function checkRewrites($filename){
        
        $className = $this->getClassNameFromFile($filename);
        
        $rewrites = $this->getRewritesFlat();
        
        if(isset($rewrites[$className])){
            foreach($rewrites[$className] as $rewriteClass){
                echo $rewriteClass['module_name'] . ' -> ' . $rewriteClass['rewrite_class'] .  " -> " . $filename . " VS " . $this->getFileNameFromClass($rewriteClass['rewrite_class']) . " \n";
            }
        }
        
    }
    
    protected function getRewritesArray(){
        if(!$this->rewrites){
            $this->rewrites = $this->getRewrites();
        }
        return $this->rewrites;
    }
    
    protected function getRewritesFlat(){
        if(!$this->rewritesFlat){
            
            $rewrites = $this->getRewritesArray();
            
            $rewritesFlatList = array();
            
            foreach($rewrites as $rewriteType=>$rewritesForType){
                foreach($rewritesForType as $coreClass=>$rewritesForCoreClass){
                    
                    if($rewriteType=='helpers'){
                        $type = 'Helper';
                    }
                    
                    if($rewriteType=='models'){
                        $type = 'Model';
                    }
                    
                    if($rewriteType=='blocks'){
                        $type = 'Block';
                    }
                    
                    $coreClassArray = explode('_',$coreClass);
                    $coreClassUpperCasedArray = array();
                    
                    $partCount = 1;
                    
                    foreach($coreClassArray as $coreClassPart){
                        $coreClassUpperCasedArray[] = ucfirst($coreClassPart);
                        
                        if($partCount==1){
                            $coreClassUpperCasedArray[] = $type;
                        }
                        
                        $partCount++;
                    }
                    
                    $coreClassUpperCased = implode('_',$coreClassUpperCasedArray);           
                    $rewritesFlatList['Mage_'.$coreClassUpperCased] = $rewritesForCoreClass;
                }
            }
        
            $this->rewritesFlat = $rewritesFlatList;    
        }
        return $this->rewritesFlat;
    }
	
	
	public function getRewrites()
    {
        $config = Mage::getModel('core/config')->init();
		
        $mergeModel = clone $config;
        
        $rewritesArray = array();
        
        $modules = $config->getNode('modules')->children();
        foreach ($modules as $modName=>$module) {
            if ($module->is('active')) {
                $configFile = $config->getModuleDir('etc', $modName).DS.'config.xml';
                if ($mergeModel->loadFile($configFile)) {
                    
                    $rewrites = $mergeModel->getNode('global/models');
                    if ($rewrites) {
                        $this->_populateRewriteArray($rewrites, $modName, 'models');
                    }
                    
                    $rewrites = $mergeModel->getNode('global/blocks');
                    if ($rewrites) {
                        $this->_populateRewriteArray($rewrites, $modName, 'blocks');
                    }
                    
                    $rewrites = $mergeModel->getNode('global/helpers');
                    if ($rewrites) {
                        $this->_populateRewriteArray($rewrites, $modName, 'helpers');
                    }
                }
            }
        }
        
        return $this->rewrites;
    }
    protected function _populateRewriteArray(Mage_Core_Model_Config_Element $rewrites, $modName, $type)
    {
        $rewrites = $rewrites->asArray();
        foreach ($rewrites as $module => $nodes) {
            if (isset($nodes['rewrite'])) {
                foreach ($nodes['rewrite'] as $classSuffix => $rewrite) {
                    $rewriteInfo = array(
                        'module_name' => $modName,
                        'rewrite_class' => $rewrite
                    );
                    $this->rewrites[$type][$module.'_'.$classSuffix][] = $rewriteInfo;
                }
            }
        }
    }
	
	protected function checkTemplateFiles($filenames){
        $designFolder = Mage::getBaseDir('app') . '/design/frontend';
        
        $templates = scandir($designFolder);
        
        foreach ($templates as $key => $subfolder ) {
            if ( !in_array( $subfolder, array( '.', '..', 'base' ) ) ) {
                $designs = scandir($designFolder . '/' . $subfolder);
                foreach($designs as $design){
                    if ( !in_array($design, array( '.', '..' ) ) ) {
                        $templatePath = $designFolder . '/' . $subfolder . '/' . $design;

                        foreach($filenames as $patchedFile){

                            $fileToCheck = $templatePath . '/' . str_replace('app/design/frontend/base/default/','',$patchedFile);

                            if(file_exists($fileToCheck)){
                                echo $fileToCheck . "\n";
                            }

                        }

                    }
                }
            }
        }
        
    }

    protected function checkSkinFiles($filenames){
        $designFolder = Mage::getBaseDir('skin') . '/frontend';

        $templates = scandir($designFolder);

        foreach ($templates as $key => $subfolder ) {
            if ( !in_array( $subfolder, array( '.', '..', 'base' ) ) ) {
                $designs = scandir($designFolder . '/' . $subfolder);
                foreach($designs as $design){
                    if ( !in_array($design, array( '.', '..' ) ) ) {
                        $templatePath = $designFolder . '/' . $subfolder . '/' . $design;

                        foreach($filenames as $patchedFile){

                            $fileToCheck = $templatePath . '/' . str_replace('skin/frontend/base/default/','',$patchedFile);

                            if(file_exists($fileToCheck)){
                                echo $fileToCheck . "\n";
                            }

                        }

                    }
                }
            }
        }

    }

    protected function searchTemplateNames($fileName){
        $fileNameParts = explode('/',$fileName);
        $fileName  = end($fileNameParts);
        array_pop($fileNameParts);
        $path = implode('/',$fileNameParts);
        $relativePathToTemplate = str_replace('app/design/frontend/base/default/template/','',$path);
        echo shell_exec('find app/design/frontend -type f -name ' . $fileName . ' -not -path "app/design/frontend/base/default/template/checkout/*" -not -path "app/design/frontend/base/default/template/persistent/*"  -not -path "app/design/frontend/rwd/default/*" -not -path "'.$path.'/*"  -not -path "*/'.$relativePathToTemplate.'/*"');
    }

    protected function searchSkinNames($fileName){
        $fileNameParts = explode('/',$fileName);
        $fileName  = end($fileNameParts);
        array_pop($fileNameParts);
        $path = implode('/',$fileNameParts);
        $relativePathToTemplate = str_replace('skin/frontend/base/default/','',$path);
        echo shell_exec('find skin/frontend -type f -name ' . $fileName . ' -not -path "skin/frontend/base/*"  -not -path "skin/frontend/rwd/default/*" -not -path "'.$path.'/*"  -not -path "*/'.$relativePathToTemplate.'/*"');
    }

    public function usageHelp()
    {
        return <<<USAGE
Usage:  php shell/patchHelper.php -- [options]

  --patch <patch_file>       Patch File (example: PATCH_SUPEE-8788_CE_1.9.0.1_v1-2016-10-11-06-57-03.sh)

  -h            Short alias for help
  help          This help

USAGE;
    }

}
$shell = new Mage_Shell_PatchHelper();
$shell->run();    
