from scipy.linalg import eigh
import sys


Atemp = sys.argv[1]
Btemp = sys.argv[2]

# Atemp = '364.8,-182.4;-182.4,182.4'
# Btemp = '.407,0;0,.407'

A = [];
for a in Atemp.split(';'):
    A.append([float(x) for x in a.split(',')])

B = [];
for b in Btemp.split(';'):
    B.append([float(x) for x in b.split(',')])

eigvals, eigvecs = eigh(A, B, eigvals_only=False)

print (','.join(str(x) for x in eigvals))
print(','.join(map(str,[ str(y) for x in eigvecs for y in x])))
