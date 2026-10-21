/**
 * A commune as the regions screen knows it.
 *
 * The association's regions follow the departments, so the screen picks
 * communes department by department; the link itself stays per commune
 * (`regionId`), which lets a region still take a department in part.
 */
export type RegionCity = {
    id: number;
    name: string;
    department: string;
    regionId: number | null;
};

export type DepartmentGroup = {
    department: string;
    cities: RegionCity[];
};

/** How much of a department a set of communes holds. */
export type DepartmentSelection = 'all' | 'some' | 'none';

/** One department a region holds, whole or in part. */
export type DepartmentCoverage = {
    department: string;
    held: number;
    total: number;
};

const byFrenchName = (a: string, b: string): number => a.localeCompare(b, 'fr');

/**
 * The communes grouped by department, departments in alphabetical order.
 * Communes keep the order they came in.
 */
export function groupByDepartment(cities: RegionCity[]): DepartmentGroup[] {
    const groups = new Map<string, RegionCity[]>();

    for (const city of cities) {
        groups.set(city.department, [
            ...(groups.get(city.department) ?? []),
            city,
        ]);
    }

    return [...groups.keys()]
        .sort(byFrenchName)
        .map((department) => ({ department, cities: groups.get(department)! }));
}

/** Whether `selected` holds all, some or none of `cities`. */
export function departmentSelection(
    cities: RegionCity[],
    selected: number[],
): DepartmentSelection {
    const held = cities.filter((city) => selected.includes(city.id)).length;

    if (held === 0) {
        return 'none';
    }

    return held === cities.length ? 'all' : 'some';
}

/**
 * The selection after ticking a department's box: a department wholly
 * selected is released, otherwise its missing communes are added.
 */
export function toggleDepartment(
    cities: RegionCity[],
    selected: number[],
): number[] {
    const ids = cities.map((city) => city.id);

    if (departmentSelection(cities, selected) === 'all') {
        return selected.filter((id) => !ids.includes(id));
    }

    return [...selected, ...ids.filter((id) => !selected.includes(id))];
}

/**
 * The departments a region holds, with how many of their communes: `held`
 * below `total` marks a department taken in part.
 */
export function regionCoverage(
    cities: RegionCity[],
    regionId: number,
): DepartmentCoverage[] {
    return groupByDepartment(cities)
        .map(({ department, cities: communes }) => ({
            department,
            held: communes.filter((city) => city.regionId === regionId).length,
            total: communes.length,
        }))
        .filter((coverage) => coverage.held > 0);
}
