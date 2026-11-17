<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;


class UI5TileNode extends UI5ButtonNode
{
    
    public function getCaption(): string
    {
        $headerNode = $this->getNodeElement()->find('css', '.sapMGTHdrTxt > .sapMText  > span');
        if ($headerNode) {
            return $headerNode->getText();
        }
        return $this->getNodeElement()->getText();
    }
}