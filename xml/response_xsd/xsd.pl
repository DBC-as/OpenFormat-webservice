while (<>) {
  if (/Body|of:/) {
    # skip;
  }
  else {
    $linie = $_;
    $linie =~ s/fullDisplay/manifestation/;
    $linie =~ s/<SOAP-ENV:Envelope/<bibdk:workDisplay/;
    $linie =~ s!</SOAP-ENV:Envelope!</bibdk:workDisplay!;
    #<SOAP-ENV:Envelope 
    print $linie;
  } 
}
