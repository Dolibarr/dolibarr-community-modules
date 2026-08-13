<?php
/* Copyright (C) 2026 Pierre Grasswill
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/
 */

/**
 *      \file       test/phpunit/fixtures/ExternalDirPDPProvider.class.php
 *      \ingroup    test
 *      \brief      Provider class living outside einvoicing/class/providers/, standing for the class
 *                  an external module ships in its own directory. Only used by
 *                  PDPProviderManagerTest to check the 'classpath' of a declared provider is really
 *                  the directory the class is loaded from.
 */

dol_include_once('einvoicing/class/providers/TestPDPProvider.class.php');

/**
 * A provider brought by "another module". It inherits everything from the sample provider: what is
 * tested here is where the class file is loaded from, not what the provider does.
 */
class ExternalDirPDPProvider extends TestPDPProvider
{
	/**
	 * @var string		Name
	 */
	public $name = 'ExternalDirPDP';
}
