-- correction supplémentaire
update eratcorrigee
  set geom=ST_SetSRID(ST_GeomFromGeoJSON('{ "type": "Polygon", "coordinates": [ [ [ 5.6257884, 48.2164472 ], [ 5.6255414, 48.2157343 ], [ 5.6245292, 48.2135574 ], [ 5.6233445, 48.2118363 ], [ 5.6223613, 48.2111883 ], [ 5.621289, 48.2101831 ], [ 5.6198828, 48.209233 ], [ 5.6176039, 48.2072264 ], [ 5.6158063, 48.2053873 ], [ 5.6157216, 48.2051191 ], [ 5.6159848, 48.2050223 ], [ 5.618664, 48.2048126 ], [ 5.619661, 48.2046041 ], [ 5.6215068, 48.2039681 ], [ 5.6229616, 48.2035676 ], [ 5.6238161, 48.2032281 ], [ 5.6242555, 48.2027213 ], [ 5.6249372, 48.200722 ], [ 5.6254741, 48.1985475 ], [ 5.6254975, 48.1978268 ], [ 5.6244001, 48.1921424 ], [ 5.6216514, 48.1890696 ], [ 5.6209457, 48.1885496 ], [ 5.6206513, 48.1881528 ], [ 5.6205146, 48.1881117 ], [ 5.6192158, 48.1867082 ], [ 5.6152072, 48.1817797 ], [ 5.6136594, 48.1806985 ], [ 5.6135112, 48.1804776 ], [ 5.6133095, 48.180483 ], [ 5.6116785, 48.1791335 ], [ 5.6115441, 48.1791374 ], [ 5.6109668, 48.1796039 ], [ 5.6101007, 48.1800012 ], [ 5.6101277, 48.1802212 ], [ 5.6098592, 48.1804549 ], [ 5.608468, 48.1808773 ], [ 5.6069949, 48.1815508 ], [ 5.6059813, 48.1817983 ], [ 5.6027583, 48.1828318 ], [ 5.601907, 48.1829307 ], [ 5.6010122, 48.1831854 ], [ 5.599958, 48.183675 ], [ 5.599056, 48.1838947 ], [ 5.5982017, 48.1842331 ], [ 5.5975487, 48.1842052 ], [ 5.5956584, 48.184777 ], [ 5.5914598, 48.1867329 ], [ 5.5901646, 48.1875536 ], [ 5.5896426, 48.1872607 ], [ 5.589172, 48.1872737 ], [ 5.5883861, 48.1876106 ], [ 5.5865122, 48.1888769 ], [ 5.5862468, 48.1889296 ], [ 5.5862044, 48.1882553 ], [ 5.585376, 48.1879187 ], [ 5.5843995, 48.1873603 ], [ 5.5841284, 48.187323 ], [ 5.5831567, 48.1879349 ], [ 5.5828878, 48.1879426 ], [ 5.5803643, 48.187382 ], [ 5.5778136, 48.1864303 ], [ 5.5782091, 48.1859814 ], [ 5.5783566, 48.1856676 ], [ 5.5784165, 48.1852116 ], [ 5.5782983, 48.1850522 ], [ 5.5777968, 48.1846796 ], [ 5.5773263, 48.1845566 ], [ 5.5766325, 48.1844917 ], [ 5.5760554, 48.1843071 ], [ 5.5730837, 48.1814793 ], [ 5.572155, 48.1817859 ], [ 5.5726954, 48.182488 ], [ 5.5741461, 48.1835556 ], [ 5.5744435, 48.1846835 ], [ 5.574126, 48.1852414 ], [ 5.5726838, 48.1860903 ], [ 5.5720029, 48.186621 ], [ 5.5715409, 48.1871982 ], [ 5.5708869, 48.1885584 ], [ 5.5702508, 48.1892357 ], [ 5.5691215, 48.1894917 ], [ 5.5691602, 48.190121 ], [ 5.5698595, 48.1913752 ], [ 5.5706609, 48.1924867 ], [ 5.5709062, 48.193017 ], [ 5.5708424, 48.1935045 ], [ 5.5700242, 48.1942336 ], [ 5.5699508, 48.1944944 ], [ 5.5711573, 48.19632 ], [ 5.5722934, 48.1972784 ], [ 5.5726832, 48.197043 ], [ 5.5728163, 48.1970392 ], [ 5.57381, 48.1978665 ], [ 5.5747229, 48.1984714 ], [ 5.5749963, 48.1985546 ], [ 5.5758043, 48.1974521 ], [ 5.5799668, 48.1972919 ], [ 5.5800719, 48.1977334 ], [ 5.5802869, 48.1977783 ], [ 5.580528, 48.1981655 ], [ 5.5815812, 48.1979498 ], [ 5.582297, 48.1994511 ], [ 5.5825783, 48.1997772 ], [ 5.5826982, 48.2013185 ], [ 5.5829061, 48.2017155 ], [ 5.58311, 48.201973 ], [ 5.584096, 48.2027203 ], [ 5.5848506, 48.2029504 ], [ 5.5836819, 48.2033946 ], [ 5.5834221, 48.2035903 ], [ 5.5833113, 48.2039393 ], [ 5.582155, 48.2038683 ], [ 5.581539, 48.2041608 ], [ 5.5802767, 48.2044828 ], [ 5.581625, 48.2056156 ], [ 5.5826464, 48.2068923 ], [ 5.5828061, 48.2072931 ], [ 5.581898, 48.2089379 ], [ 5.5817294, 48.2094827 ], [ 5.5809447, 48.2103749 ], [ 5.5814353, 48.2108233 ], [ 5.581543, 48.2111252 ], [ 5.5817607, 48.2132613 ], [ 5.5803234, 48.213976 ], [ 5.5803385, 48.2142007 ], [ 5.581214, 48.2153015 ], [ 5.5806316, 48.2156771 ], [ 5.581115, 48.2169683 ], [ 5.5821047, 48.2188309 ], [ 5.5801521, 48.2199197 ], [ 5.5808068, 48.2217914 ], [ 5.5810363, 48.223315 ], [ 5.5808082, 48.2239961 ], [ 5.5798976, 48.2255968 ], [ 5.5797483, 48.2264553 ], [ 5.5822268, 48.2262518 ], [ 5.5857544, 48.2255696 ], [ 5.5868329, 48.2255847 ], [ 5.5898545, 48.2265358 ], [ 5.5911292, 48.2264556 ], [ 5.5915235, 48.2263102 ], [ 5.5914425, 48.2260878 ], [ 5.5917037, 48.2259452 ], [ 5.5932265, 48.2255427 ], [ 5.5933554, 48.2254498 ], [ 5.5956586, 48.2256558 ], [ 5.596003, 48.2257815 ], [ 5.5973529, 48.2258337 ], [ 5.5985602, 48.225755 ], [ 5.5993502, 48.225463 ], [ 5.6005011, 48.2244861 ], [ 5.6011254, 48.2237044 ], [ 5.6026357, 48.223077 ], [ 5.6032853, 48.222699 ], [ 5.6042097, 48.2224031 ], [ 5.6057746, 48.2222272 ], [ 5.6062977, 48.2217989 ], [ 5.6069511, 48.2215531 ], [ 5.6079952, 48.220953 ], [ 5.6088404, 48.2206102 ], [ 5.6091098, 48.2203909 ], [ 5.610726, 48.2196197 ], [ 5.6114017, 48.2200432 ], [ 5.6121746, 48.2216421 ], [ 5.6124584, 48.2229385 ], [ 5.6121863, 48.225061 ], [ 5.6127834, 48.2259895 ], [ 5.6126012, 48.2263095 ], [ 5.6117195, 48.2272788 ], [ 5.6117864, 48.2283567 ], [ 5.6122881, 48.2299184 ], [ 5.6135731, 48.2300169 ], [ 5.616532, 48.2299343 ], [ 5.6187785, 48.2303221 ], [ 5.6199708, 48.2300184 ], [ 5.6203745, 48.2300076 ], [ 5.6209265, 48.2302168 ], [ 5.620928, 48.2303824 ], [ 5.6203971, 48.2306805 ], [ 5.6211027, 48.2308872 ], [ 5.622041, 48.230816 ], [ 5.6246264, 48.230159 ], [ 5.6250184, 48.2299675 ], [ 5.6267663, 48.2299188 ], [ 5.6282985, 48.2296506 ], [ 5.6295095, 48.2296174 ], [ 5.6316714, 48.2297367 ], [ 5.6334031, 48.2294181 ], [ 5.6343414, 48.2293468 ], [ 5.6340148, 48.2279082 ], [ 5.6351422, 48.2281163 ], [ 5.6353688, 48.2274729 ], [ 5.6349278, 48.2258208 ], [ 5.6346309, 48.2253791 ], [ 5.6334536, 48.2248715 ], [ 5.6329527, 48.2243911 ], [ 5.632571, 48.2236821 ], [ 5.6314229, 48.2225436 ], [ 5.6309207, 48.2209829 ], [ 5.630531, 48.220139 ], [ 5.630349, 48.2193797 ], [ 5.6297517, 48.2184513 ], [ 5.6295522, 48.2185016 ], [ 5.6291221, 48.2191441 ], [ 5.6266706, 48.218717 ], [ 5.6263971, 48.2186348 ], [ 5.6268886, 48.217901 ], [ 5.6263205, 48.2174221 ], [ 5.6257884, 48.2164472 ] ] ] }'), 4326)
  where id='52064';

update eratcorrigee
  set geom=ST_SetSRID(ST_GeomFromGeoJSON('{ "type": "Polygon", "coordinates": [ [ [ 4.7772684, 49.6656255 ], [ 4.7751396, 49.6664511 ], [ 4.7733405, 49.6670022 ], [ 4.7716269, 49.6676653 ], [ 4.768202, 49.6687811 ], [ 4.7673725, 49.6703068 ], [ 4.7668362, 49.6716609 ], [ 4.7665256, 49.6715297 ], [ 4.76479, 49.6730883 ], [ 4.7655072, 49.6734023 ], [ 4.7643866, 49.6738806 ], [ 4.7629997, 49.674226 ], [ 4.7621537, 49.6742724 ], [ 4.76177, 49.6747139 ], [ 4.7608173, 49.6754045 ], [ 4.7619075, 49.6758938 ], [ 4.7609862, 49.6769749 ], [ 4.7637512, 49.6781554 ], [ 4.7640214, 49.6783987 ], [ 4.7635049, 49.6795278 ], [ 4.7630315, 49.6800218 ], [ 4.7625162, 49.6812696 ], [ 4.7622132, 49.6838966 ], [ 4.7623929, 49.6842859 ], [ 4.7642164, 49.6855906 ], [ 4.7647632, 49.6861012 ], [ 4.7647698, 49.686253 ], [ 4.7636404, 49.6868455 ], [ 4.7633076, 49.6877617 ], [ 4.7631678, 49.6888908 ], [ 4.762199, 49.6900049 ], [ 4.7616919, 49.6902477 ], [ 4.7622038, 49.6903464 ], [ 4.7620869, 49.6904532 ], [ 4.7589306, 49.6917733 ], [ 4.7590946, 49.6923542 ], [ 4.7589527, 49.6931445 ], [ 4.7578809, 49.6931582 ], [ 4.7576034, 49.6940358 ], [ 4.7567683, 49.6945701 ], [ 4.7587938, 49.6945273 ], [ 4.7602434, 49.6946592 ], [ 4.7620769, 49.6940682 ], [ 4.7637243, 49.6942708 ], [ 4.7666804, 49.6941318 ], [ 4.7674847, 49.6942333 ], [ 4.7710584, 49.6934462 ], [ 4.7733872, 49.6928002 ], [ 4.7746663, 49.6926081 ], [ 4.7766077, 49.692099 ], [ 4.7761288, 49.6912341 ], [ 4.7759666, 49.6906307 ], [ 4.7755622, 49.6907157 ], [ 4.7748056, 49.6894712 ], [ 4.7775633, 49.6884971 ], [ 4.7760224, 49.6866474 ], [ 4.7776612, 49.6861669 ], [ 4.7777768, 49.6857078 ], [ 4.7780589, 49.6854933 ], [ 4.7790999, 49.6848355 ], [ 4.7796703, 49.6848235 ], [ 4.7812627, 49.6825758 ], [ 4.7812192, 49.6823193 ], [ 4.7814027, 49.6812228 ], [ 4.78139991945672, 49.681222209894898 ], [ 4.7804245, 49.6810152 ], [ 4.7791755, 49.680469 ], [ 4.7770252, 49.6791765 ], [ 4.7765217, 49.6786356 ], [ 4.7760232, 49.6778412 ], [ 4.7758698, 49.6773724 ], [ 4.7757728, 49.6768499 ], [ 4.7759854, 49.6766715 ], [ 4.7760265, 49.6759788 ], [ 4.776464, 49.6740167 ], [ 4.7766228, 49.6716451 ], [ 4.7768392, 49.6704187 ], [ 4.7775867, 49.6691729 ], [ 4.7775394, 49.6688464 ], [ 4.7777232, 49.6681579 ], [ 4.7782787, 49.6673651 ], [ 4.7787034, 49.6670731 ], [ 4.7784403, 49.6670725 ], [ 4.7772684, 49.6656255 ] ] ] }'), 4326)
  where id='08079';
