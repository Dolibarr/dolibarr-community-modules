#!/bin/bash
#------------------------------------------------------
# Script to deduplicate alternate language files (xx_YY) of modules
# by removing entries already identical into their parent language file (xx_XX)
#
# Laurent Destailleur (eldy) - eldy@users.sourceforge.net
#------------------------------------------------------
# Usage: ./dev/translation/fixaltlanguages.sh (list|fix) [ll_CC]
# Run it from the root directory of the repository.
#------------------------------------------------------


DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd $DIR/../..

# Syntax
if [ "x$1" != "xlist" ] && [ "x$1" != "xfix" ]
then
	echo "Scan alternate language files and remove entries found in parent file"
	echo "Usage: ./dev/translation/fixaltlanguages.sh (list|fix) [ll_CC]"
	exit 1
fi

ACTION=$1
LANGFILTER=$2
exit_code=0

# Loop on each language directory of each module
for langdir in `find */langs/* -type d | sort`
do
	lang=`basename $langdir`
	module=`echo $langdir | cut -d '/' -f 1`

	if [ "x$LANGFILTER" != "x" ] && [ "x$lang" != "x$LANGFILTER" ]
	then
		continue
	fi

	aa=`echo $lang | cut -d_ -f1`
	bb=`echo $lang | cut -d_ -f2`
	bblower=`echo $bb | tr '[:upper:]' '[:lower:]'`

	# Main languages (xx_XX) and en_US source file are not alternates
	if [ "x$aa" = "x$bblower" ] || [ "x$lang" = "xen_US" ]
	then
		continue
	fi

	# Get code of parent language
	aaupper=`echo $aa | tr '[:lower:]' '[:upper:]'`
	if [ "x$aaupper" = "xEN" ]
	then
		aaupper="US"
	fi
	if [ "x$aaupper" = "xEL" ]
	then
		aaupper="GR"
	fi
	if [ "x$bb" = "xEG" ] || [ "x$bb" = "xIQ" ]
	then
		aaupper="SA"
	fi

	parentlang="${aa}_${aaupper}"
	parentdir="$module/langs/$parentlang"

	# If there is no parent language directory, there is nothing to deduplicate
	if [ ! -d "$parentdir" ]
	then
		continue
	fi

	echo "***** Module $module: language '$lang' is an alternate of '$parentlang'"

	if [ "x$ACTION" = "xlist" ]
	then
		continue
	fi

	for file in `ls $module/langs/$lang/*.lang 2>/dev/null`
	do
		basefile=`basename $file`

		if [ ! -f "$parentdir/$basefile" ]
		then
			echo "No parent file for $file, we discard it"
			continue
		fi

		echo "dev/translation/strip_language_file.php $parentlang $lang $basefile (module $module)"
		php "$DIR/strip_language_file.php" "$parentdir" "$module/langs/$lang" "$basefile"

		# Replace the file with the delta only if content differs
		if [ -f "$file.delta" ]
		then
			if diff -q "$file" "$file.delta" >/dev/null
			then
				rm "$file.delta"
			else
				mv "$file.delta" "$file"
				echo "File $file was updated"
				exit_code=1
			fi
		fi

		# If only the header line remains, the alternate file is useless
		if [ -f "$file" ] && [ `wc -l < "$file"` -le 1 ]
		then
			echo "Only one line remaining in file '$file', we delete it"
			rm "$file"
			exit_code=1
		fi
	done
done

exit $exit_code
