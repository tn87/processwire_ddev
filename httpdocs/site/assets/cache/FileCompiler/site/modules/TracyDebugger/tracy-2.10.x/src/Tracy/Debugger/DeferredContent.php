<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Tracy;


/**
 * @internal
 */
final class DeferredContent
{
	private SessionStorage $sessionStorage;
	private string $requestId;
	private bool $useSession = false;


	public function __construct(SessionStorage $sessionStorage)
	{
		$this->sessionStorage = $sessionStorage;
		$this->requestId = $_SERVER['HTTP_X_TRACY_AJAX'] ?? Helpers::createId();
	}


	public function isAvailable(): bool
	{
		return $this->useSession && $this->sessionStorage->isAvailable();
	}


	public function getRequestId(): string
	{
		return $this->requestId;
	}


	public function &getItems(string $key): array
	{
		$items = &$this->sessionStorage->getData()[$key];
		$items = (array) $items;
		return $items;
	}


	public function addSetup(string $method, mixed $argument): void
	{
		$argument = json_encode($argument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		$item = &$this->getItems('setup')[$this->requestId];
		$item['code'] = ($item['code'] ?? '') . "$method($argument);\n";
		$item['time'] = time();
	}


	public function sendAssets(): bool
	{
		if (headers_sent($file, $line) || ob_get_length()) {
			throw new \LogicException(
				__METHOD__ . '() called after some output has been sent. '
				. ($file ? "Output started at $file:$line." : 'Try Tracy\OutputDebugger to find where output started.'),
			);
		}

		$asset = $_GET['_tracy_bar'] ?? null;
		if ($asset === 'js') {
			header('Content-Type: application/javascript; charset=UTF-8');
			header('Cache-Control: max-age=864000');
			header_remove('Pragma');
			header_remove('Set-Cookie');
			$str = $this->buildJsCss();
			header('Content-Length: ' . strlen($str));
			echo $str;
			flush();
			return true;
		}

		$this->useSession = $this->sessionStorage->isAvailable();
		if (!$this->useSession) {
			return false;
		}

		$this->clean();

		if (is_string($asset) && preg_match('#^content(-ajax)?\.(\w+)$#', $asset, $m)) {
			[, $ajax, $requestId] = $m;
			header('Content-Type: application/javascript; charset=UTF-8');
			header('Cache-Control: max-age=60');
			header_remove('Set-Cookie');
			$str = $ajax ? '' : $this->buildJsCss();
			$data = &$this->getItems('setup');
			$str .= $data[$requestId]['code'] ?? '';
			unset($data[$requestId]);
			header('Content-Length: ' . strlen($str));
			echo $str;
			flush();
			return true;
		}

		if (Helpers::isAjax()) {
			header('X-Tracy-Ajax: 1'); // session must be already locked
		}

		return false;
	}


	private function buildJsCss(): string
	{
		$css = array_map('file_get_contents', array_merge([
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../assets/reset.css',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../Bar/assets/bar.css',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../assets/toggle.css',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../assets/table-sort.css',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../assets/tabs.css',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../Dumper/assets/dumper-light.css',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../Dumper/assets/dumper-dark.css',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../BlueScreen/assets/bluescreen.css',
		], Debugger::$customCssFiles));

		$js1 = array_map(fn($file) => '(function() {' . file_get_contents($file) . '})();', [
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../Bar/assets/bar.js',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../assets/toggle.js',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../assets/table-sort.js',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../assets/tabs.js',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../Dumper/assets/dumper.js',
			'/var/www/html/httpdocs/site/modules/TracyDebugger/tracy-2.10.x/src/Tracy/Debugger' . '/../BlueScreen/assets/bluescreen.js',
		]);
		$js2 = array_map('file_get_contents', Debugger::$customJsFiles);

		$str = "'use strict';
(function(){
	var el = document.createElement('style');
	el.setAttribute('nonce', document.currentScript.getAttribute('nonce') || document.currentScript.nonce);
	el.className='tracy-debug';
	el.textContent=" . json_encode(Helpers::minifyCss(implode('', $css))) . ";
	document.head.appendChild(el);})
();\n" . implode('', $js1) . implode('', $js2);

		if(Debugger::$customCssStr) $str .= "(function(){var el = document.createElement('div'); el.className='tracy-debug'; el.innerHTML='".preg_replace('#\s+#u', ' ', Debugger::$customCssStr)."'; document.head.appendChild(el);})();\n";

		if(Debugger::$customJsStr) $str .= Debugger::$customJsStr;

		if(Debugger::$customBodyStr) $str .= "(function(){var el = document.createElement('div'); el.className='tracy-debug'; el.innerHTML='".preg_replace('#\s+#u', ' ', Debugger::$customBodyStr)."'; document.body.appendChild(el);})();\n";

		return $str;
	}


	public function clean(): void
	{
		foreach ($this->sessionStorage->getData() as &$items) {
			$items = array_slice((array) $items, -10, null, true);
			$items = array_filter($items, fn($item) => isset($item['time']) && $item['time'] > time() - 60);
		}
	}
}
