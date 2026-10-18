#!/bin/bash
#------------------------------------------------------
# Script to push language files to Transifex
#
# Laurent Destailleur (eldy) - eldy@users.sourceforge.net
#------------------------------------------------------
# Usage: txpush.sh (source|xx_XX|all) [-r module.file] [-f]
#------------------------------------------------------


export project='dolibarr-community-modules'

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd $DIR/../..

# Syntax
if [ "x$1" = "x" ]
then
	echo "This push local files to transifex."
	echo "Note:  If you push a language file (not source), file will be skipped if transifex file is newer."
	echo "       Using -f will overwrite translation but not memory."
	echo "       Using 'all' will push all languages, except the en_US source language."
	echo "Usage: ./dev/translation/txpush.sh (source|xx_XX|all) [-r $project.file] [-f] [--no-interactive]"
	exit
fi

if [ ! -d ".tx" ]
then
	echo "Script must be ran from root directory of project with command ./dev/translation/txpush.sh"
	exit
fi

if [ "x$1" = "xsource" ]
then
	echo "tx push -s $2 $3"
	tx push -s $2 $3
else
	# Extract the -r option, when defined, to push only the requested resource (all other options are transmitted to tx)
	language=$1
	resource=""
	options=""
	shift
	while [ $# -gt 0 ]
	do
		case "$1" in
		-r)
			if [ $# -gt 1 ] && [ "x${2:0:1}" != "x-" ]
			then
				resource=`echo $2 | sed -e 's/^'$project'\.//'`
				shift
			else
				options="$options $1"
			fi
			;;
		*)
			options="$options $1"
			;;
		esac
		shift
	done

	# Build the list of languages to push: "all" means all languages, except the source language en_US
	if [ "x$language" = "xall" ]
	then
		languages=`find */langs/* -type d | cut -d '/' -f 3 | sort -u | grep -v '^en_US$'`
	else
		languages=$language
	fi

	for lang in $languages
	do
		for file in `find */langs/$lang/*.lang -type f`
		do
			export basefile=`basename $file | sed -s s/\.lang//g`

			if [ "x$resource" != "x" ] && [ "x$resource" != "x$basefile" ]
			then
				continue
			fi

			echo "tx push --skip -r $project.$basefile -t -l $lang $options"
			tx push --skip -r $project.$basefile -t -l $lang $options
		done
	done
fi
