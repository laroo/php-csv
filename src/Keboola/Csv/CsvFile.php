<?php
/**
 *
 * User: Martin Halamíček
 * Date: 13.4.12
 * Time: 15:31
 *
 */

namespace Keboola\Csv;

class CsvFile extends \SplFileInfo implements \Iterator
{
	const DEFAULT_DELIMITER = ',';
	const DEFAULT_ENCLOSURE = '"';

    # characters to ignore when attempting to auto-detect delimiter
    protected $auto_non_chars = "a-zA-Z0-9\n\r";

	protected $_delimiter;
	protected $_enclosure;

	protected $_filePointer;
	protected $_rowCounter = 0;
	protected $_currentRow;
	protected $_lineBreak;

	public function __construct($fileName, $delimiter = self::DEFAULT_DELIMITER, $enclosure = self::DEFAULT_ENCLOSURE)
	{
		parent::__construct($fileName);

        $this->_setEnclosure($enclosure);
		if (!$delimiter) {
            // Detect
            $this->getDelimiter();
        } else {
            $this->_setDelimiter($delimiter);
        }

	}

	/**
	 * @param $delimiter
	 * @return CsvFile
	 */
	protected function _setDelimiter($delimiter)
	{
		$this->_validateDelimiter($delimiter);
		$this->_delimiter = $delimiter;
		return $this;
	}

	protected function _validateDelimiter($delimiter)
	{
		if (strlen($delimiter) > 1) {
			throw new InvalidArgumentException("Delimiter must be a single character. \"$delimiter\" received",
				Exception::INVALID_PARAM, NULL, 'invalidParam');
		}

		if (strlen($delimiter) == 0) {
			throw new InvalidArgumentException("Delimiter cannot be empty.",
				Exception::INVALID_PARAM, NULL, 'invalidParam');
		}
	}

	public function getDelimiter()
    {
        if (!$this->_delimiter) {
            $this->_delimiter = $this->_detectDelimiter();
        }
        return $this->_delimiter;
	}

	public function getEnclosure()
	{
		return $this->_enclosure;
	}

	public function getEscapedBy()
	{
		return $this->getEnclosure();
	}

	/**
	 * @param $enclosure
	 * @return CsvFile
	 */
	protected  function _setEnclosure($enclosure)
	{
		$this->_validateEnclosure($enclosure);
		$this->_enclosure = $enclosure;
		return $this;
	}

	protected function _validateEnclosure($enclosure)
	{
		if (strlen($enclosure) > 1) {
			throw new InvalidArgumentException("Enclosure must be a single character. \"$enclosure\" received",
				Exception::INVALID_PARAM, NULL, 'invalidParam');
		}
	}


	public function getColumnsCount()
	{
		return count($this->getHeader());
	}

	public function getHeader()
	{
		$this->rewind();
		$current = $this->current();
		if (is_array($current)) {
			return $current;
		}

		return array();
	}

	public function writeRow(array $row)
	{
		fwrite($this->_getFilePointer('w+'), $this->rowToStr($row));
	}

	public function rowToStr(array $row)
	{
		$return = array();
		foreach ($row as $column) {
			$return[] = $this->getEnclosure()
				. str_replace($this->getEnclosure(), str_repeat($this->getEnclosure(), 2), $column) . $this->getEnclosure();
		}
		return implode($this->liter(), $return) . "\n";
	}

	public function getLineBreak()
	{
		if (!$this->_lineBreak) {
			$this->_lineBreak = $this->_detectLineBreak();
		}
		return $this->_lineBreak;
	}

	public function getLineBreakAsText()
	{
		return trim(json_encode($this->getLineBreak()), '"');
	}

	public function validateLineBreak()
	{
		$lineBreak = $this->getLineBreak();
		if (in_array($lineBreak, array("\r\n", "\n", "\r"))) {
			return $lineBreak;
		}

		throw new InvalidArgumentException("Invalid line break. Please use unix \\n or win \\r\\n line breaks.",
			Exception::INVALID_PARAM, NULL, 'invalidParam');
	}

	protected  function _detectLineBreak()
	{

		rewind($this->_getFilePointer());
		$sample = fread($this->_getFilePointer(), 10000);
		rewind($this->_getFilePointer());

		$possibleLineBreaks = array(
			"\r\n", // win
			"\r", // mac
			"\n", // unix
		);

		$lineBreaksPositions = array();
		foreach($possibleLineBreaks as $lineBreak) {
			$position = strpos($sample, $lineBreak);
			if ($position === false) {
				continue;
			}
			$lineBreaksPositions[$lineBreak] = $position;
		}


		asort($lineBreaksPositions);
		reset($lineBreaksPositions);

		return empty($lineBreaksPositions) ? "\n" : key($lineBreaksPositions);
	}

	protected function _closeFile()
	{
		if (is_resource($this->_filePointer)) {
			fclose($this->_filePointer);
		}
	}

	public function __destruct()
	{
		$this->_closeFile();
	}

	/**
	 * (PHP 5 &gt;= 5.1.0)<br/>
	 * Return the current element
	 * @link http://php.net/manual/en/iterator.current.php
	 * @return mixed Can return any type.
	 */
	public function current()
	{
		return $this->_currentRow;
	}

	/**
	 * (PHP 5 &gt;= 5.1.0)<br/>
	 * Move forward to next element
	 * @link http://php.net/manual/en/iterator.next.php
	 * @return void Any returned value is ignored.
	 */
	public function next()
	{
		$this->_currentRow = $this->_readLine();
		$this->_rowCounter++;
	}

	/**
	 * (PHP 5 &gt;= 5.1.0)<br/>
	 * Return the key of the current element
	 * @link http://php.net/manual/en/iterator.key.php
	 * @return scalar scalar on success, integer
	 * 0 on failure.
	 */
	public function key()
	{
		return $this->_rowCounter;
	}

	/**
	 * (PHP 5 &gt;= 5.1.0)<br/>
	 * Checks if current position is valid
	 * @link http://php.net/manual/en/iterator.valid.php
	 * @return boolean The return value will be casted to boolean and then evaluated.
	 * Returns true on success or false on failure.
	 */
	public function valid()
	{
		return $this->_currentRow !== false;
	}

	/**
	 * (PHP 5 &gt;= 5.1.0)<br/>
	 * Rewind the Iterator to the first element
	 * @link http://php.net/manual/en/iterator.rewind.php
	 * @return void Any returned value is ignored.
	 */
	public function rewind()
	{
		rewind($this->_getFilePointer());
		$this->_currentRow = $this->_readLine();
		$this->_rowCounter = 0;
	}

	protected function _readLine()
	{
		$this->validateLineBreak();

		// allow empty enclosure hack
		$enclosure = !$this->getEnclosure() ? chr(0) : $this->getEnclosure();
		return fgetcsv($this->_getFilePointer(), null, $this->getDelimiter(), $enclosure, '"');
	}

	protected function _getFilePointer($mode = 'r')
	{
		if (!is_resource($this->_filePointer)) {
			$this->_openFile($mode);
		}
		return $this->_filePointer;
	}

	protected function _openFile($mode)
	{
		if ($mode == 'r' && !is_file($this->getPathname())) {
			throw new Exception("Cannot open file $this",
					Exception::FILE_NOT_EXISTS, NULL, 'fileNotExists');
		}
		$this->_filePointer = fopen($this->getPathname(), $mode);
		if (!$this->_filePointer) {
			throw new Exception("Cannot open file $this",
				Exception::FILE_NOT_EXISTS, NULL, 'fileNotExists');
		}
	}

    /**
     * Attempts to guess the delimiter of a set of data
     *
     * @param string The data you would like to get the delimiter of
     * @access protected
     * @return mixed If a delimiter can be found it is returned otherwise false is returned
     * @todo - understand what's going on here (I haven't yet had a chance to really look at it)
     */
    public function _detectDelimiter() {

        $linefeed = $this->getLineBreak();
        $quotechar = $this->getEnclosure();

        $search_depth = 15;
        $enclosure = $this->getEnclosure();

        # preferred delimiter characters, only used when all filtering method
        # returns multiple possible delimiters (happens very rarely)
        $preferred = ",;\t.:|";

        rewind($this->_getFilePointer());
        $data = fread($this->_getFilePointer(), 10000);
        rewind($this->_getFilePointer());

        $chars = array();
        $strlen = strlen($data);
        $enclosed = false;
        $n = 1;
        $to_end = true;

        // walk specific depth finding posssible delimiter characters
        for ( $i=0; $i < $strlen; $i++ ) {
            $ch = $data{$i};
            $nch = ( isset($data{$i+1}) ) ? $data{$i+1} : false ;
            $pch = ( isset($data{$i-1}) ) ? $data{$i-1} : false ;

            // open and closing quotes
            if ( $ch == $enclosure ) {
                if ( !$enclosed || $nch != $enclosure ) {
                    $enclosed = ( $enclosed ) ? false : true ;
                } elseif ( $enclosed ) {
                    $i++;
                }

                // end of row
            } elseif ( ($ch == "\n" && $pch != "\r" || $ch == "\r") && !$enclosed ) {
                if ( $n >= $search_depth ) {
                    $strlen = 0;
                    $to_end = false;
                } else {
                    $n++;
                }

                // count character
            } elseif (!$enclosed) {
                if ( !preg_match('/['.preg_quote($this->auto_non_chars, '/').']/i', $ch) ) {
                    if ( !isset($chars[$ch][$n]) ) {
                        $chars[$ch][$n] = 1;
                    } else {
                        $chars[$ch][$n]++;
                    }
                }
            }
        }

        // filtering
        $depth = ( $to_end ) ? $n-1 : $n ;
        $filtered = array();
        foreach( $chars as $char => $value ) {
            if ( $match = $this->_check_count($char, $value, $depth, $preferred) ) {
                $filtered[$match] = $char;
            }
        }

        // capture most probable delimiter
        ksort($filtered);
        $delim = reset($filtered);

        return $delim;

    }

    /**
     * Check if passed info might be delimiter
     *  - only used by find_delimiter()
     * @return  special string used for delimiter selection, or false
     */
    function _check_count ($char, $array, $depth, $preferred) {
        if ( $depth == count($array) ) {
            $first = null;
            $equal = null;
            $almost = false;
            foreach( $array as $key => $value ) {
                if ( $first == null ) {
                    $first = $value;
                } elseif ( $value == $first && $equal !== false) {
                    $equal = true;
                } elseif ( $value == $first+1 && $equal !== false ) {
                    $equal = true;
                    $almost = true;
                } else {
                    $equal = false;
                }
            }
            if ( $equal ) {
                $match = ( $almost ) ? 2 : 1 ;
                $pref = strpos($preferred, $char);
                $pref = ( $pref !== false ) ? str_pad($pref, 3, '0', STR_PAD_LEFT) : '999' ;
                return $pref.$match.'.'.(99999 - str_pad($first, 5, '0', STR_PAD_LEFT));
            } else return false;
        }
    }

}
