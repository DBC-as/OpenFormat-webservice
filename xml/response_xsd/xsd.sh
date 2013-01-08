for i in ../response/*.xml
do
FILNAVN=`basename $i`
echo $FILNAVN
  xmllint -format ../response/$FILNAVN | perl xsd.pl >$FILNAVN
done
