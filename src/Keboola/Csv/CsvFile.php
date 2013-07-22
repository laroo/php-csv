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

        rewind($this->_getFilePointer());
        $data = fread($this->_getFilePointer(), 10000);
        rewind($this->_getFilePointer());

        $charcount = count_chars($data, 1);

        $filtered = array();
        foreach ($charcount as $char => $count) {
            if ($char == ord($quotechar)) {
                // exclude the quote char
                continue;
            }
            if ($char == ord(" ")) {
                // exclude spaces
                continue;
            }
            if ($char >= ord("a") && $char <= ord("z")) {
                // exclude a-z
                continue;
            }
            if ($char >= ord("A") && $char <= ord("Z")) {
                // exclude A-Z
                continue;
            }
            if ($char >= ord("0") && $char <= ord("9")) {
                // exclude 0-9
                continue;
            }
            if ($char == ord("\n") || $char == ord("\r")) {
                // exclude linefeeds
                continue;
            }
            $filtered[$char] = $count;
        }

        // count every character on every line
        $data = explode($linefeed, $data);
        $tmp = array();
        $linecount = 0;
        foreach ($data as $row) {
            if (empty($row)) {
                continue;
            }

            // count non-empty lines
            $linecount++;

            // do a charcount on this line, but only remember the chars that
            // survived the filtering above
            $frequency = array_intersect_key(count_chars($row, 1), $filtered);

            // store the charcount along with the previous counts
            foreach ($frequency as $char => $count) {
                if (!array_key_exists($char, $tmp)) {
                    $tmp[$char] = array();
                }
                $tmp[$char][] = $count; // this $char appears $count times on this line
            }
        }

        // a potential delimiter must be present on every non-empty line
        foreach ($tmp as $char=>$array) {
            if (count($array) < 0.98 * $linecount) {
                // ... so drop any delimiters that aren't
                unset($tmp[$char]);
            }
        }

        foreach ($tmp as $char => $array) {
            // a delimiter is very likely to occur the same amount of times on every line,
            // so drop delimiters that have too much variation in their frequency
            $dev = $this->deviation($array);
            if ($dev > 0.5) { // threshold not scientifically determined or something
                unset($tmp[$char]);
                continue;
            }

            // calculate average number of appearances
            $tmp[$char] = array_sum($tmp[$char]) / count($tmp[$char]);
        }

        // now, prefer the delimiter with the highest average number of appearances
        if (count($tmp) > 0) {
            asort($tmp);
            $delim = chr(end(array_keys($tmp)));
        } else {
            // no potential delimiters remain
            $delim = false;
        }

        return $delim;

    }

    /**
     * @todo - understand what's going on here (I haven't yet had a chance to really look at it)
     */
    protected function deviation ($array){

        $avg = array_sum($array) / count($array);
        foreach ($array as $value) {
            $variance[] = pow($value - $avg, 2);
        }
        $deviation = sqrt(array_sum($variance) / count($variance));
        return $deviation;

    }

}
